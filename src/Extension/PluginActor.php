<?php

/**
 * @package     Brambring.Plugin
 * @subpackage  System.Ubeeo
 * @version    24.02.01
 * @copyright  2025 Bram Brambring
 * @license    GNU General Public License version 3 or later;
 */

namespace Brambring\Plugin\System\Ubeeo\Extension;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Event\Content;
use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Component\Fields\Administrator\Table as FieldTables;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseQuery;
use Joomla\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Filter\OutputFilter;
use Joomla\Registry\Registry;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 *  Ubeeo Plugin.
 *
 * @since 25.52.7239
 */
final class PluginActor extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use TaskPluginTrait;

    protected $allowLegacyListeners = false;
    protected $autoloadLanguage     = true;
    private $extractedListfields    = [];
    private $vacancyArticles        = [];
    private $didSetUser             = false;
    private $updateCount            = 0;
    private $totalCount             = 0;
    private const TASKS_MAP         = [
        'ubeeo.tasks' => [
            'langConstPrefix' => 'PLG_SYSTEM_UBEEO_TASKS',
            'method'          => 'taskUbeeo',
        ],
    ];
    public function onAjaxUbeeo(AjaxEvent $event)
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        if ($app->get('debug', false)) { // debug  will result in memory problems
            $event->updateEventResult(Text::_("PLG_SYSTEM_UBEEO_NOT_DEBUG"));
            return;
        };
        $key   = $input->get('hash');
        if ($key != $this->getHashKey()) {
            $event->updateEventResult(Status::KNOCKOUT);
            return;
        }
        try {
            $this->setUser();
            $jobData = $this->processFeed();
            $result  = [
                't' => $this->totalCount,
                'u' => $this->updateCount,
            ];


            if ($input->get('dump', 0) == 1) {
                $result['dump'] = $jobData;
            }

            $event->updateEventResult($result);
        } catch (\RuntimeException $e) {
            $this->unsetUser();
            $event->updateEventResult($e->getMessage());
            throw new \Exception($e->getMessage());
        }
        $this->unsetUser();
    }
    private function getHashKey()
    {

        return  ComponentHelper::getParams('com_scheduler')->get('webcron.key');
    }

    private function checkAuthorise(?int $user_id = null)
    {

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($user_id);

        if (!$user->authorise('core.edit.own', 'com_content.article')) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_CANNOTEDIT'));
        }
        if (!$user->authorise('core.edit.state', 'com_content.article')) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_CANNOTSTATE'));
        }
        if (!$user->authorise('core.delete', 'com_content.article')) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_CANNOTDELETE'));
        }

        if (!$user->authorise('core.edit.value', 'com_content.field')) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_CANNOTEDITFIELDVALUE'));
        }
    }

    private function setUser()
    {

        $user = $this->getApplication()->getIdentity();

        if (!$user->id) {
            $id      = $this->params->get('user_id', 0);
            if ($id) {
                $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id);
                $this->logTask(Text::_("PLG_SYSTEM_UBEEO_LOG_TASK_SETTING_USER"), 'info');
                $this->didSetUser = true;
                $this->getApplication()->getSession()->set('user', $user);
                $this->getApplication()->loadIdentity($user);
            }
        };
        //checkParams before import checks permissions
    }
    public function onError(ErrorEvent $event)
    {
        $error = $event->getError();
        try {
            if (((int) $event->getError()->getCode() !== 404)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }


        $menu = $this->getApplication()->getMenu()->getActive();
        if ($menu->id != $this->params->get('menuid', 0)) {
            return;
        }

        $input  = $this->getApplication()->getInput();
        $method = $input->getMethod();


        //'ready' for PUT AND DELETE
        switch ($method) {
            case 'GET':
                $id = $this->getIdFromUri();
                if (!$id) {
                    //soft 404
                    $this->sendToMenu($menu);
                }

                $article = $this->getArticleByVacancy($id);
                if (!$article) {
                    //soft 404
                    $this->sendToMenu($menu);
                }

                $this->sendToMenu($menu, $article);
                break;
            case 'DELETE':
                if (!$this->checkApiKey()) {
                    $this->headerAndClose(403);
                }
                $id = $this->getIdFromUri();
                if (!$id) {
                    $this->headerAndClose(406);
                }

                $article = $this->getArticleByVacancy($id);
                if (!$article) {
                    $this->headerAndClose(404);
                }
                $this->vacancyArticles = [
                    [
                        'id' => $article['id'],
                    ],
                ];
                $this->setUser();
                $this->processObsoleteVacancies();
                $this->unsetUser();
                $this->headerAndClose(204);
                break;

            case 'PUT':
                //'hack' to work around libraries/src/MVC/Model/FormBehaviorTrait.php (line 77)
                if (!\defined('JPATH_COMPONENT')) {
                    \define('JPATH_COMPONENT', JPATH_ROOT . '/components/com_content');
                }
                if (!$this->checkApiKey()) {
                    $this->headerAndClose(403);
                    return;
                }

                $id = $this->getIdFromUri();
                if (!$id) {
                    $this->headerAndClose(406);
                    return;
                }

                $raw = $this->getApplication()->getInput()->json->getRaw();
                if (!$raw) {
                    $this->headerAndClose(418);
                    return;
                }
                $vacancie = json_decode($raw);
                if ($id !== $vacancie->id ?? 0) {
                    $this->headerAndClose(406);
                    return;
                }

                $this->setUser();
                $this->getApplication()->loadDocument(); //set a dummy document.
                $id = $this->processVacancie($vacancie);
                $this->updateListfields(true);
                $this->unsetUser();

                $article = $this->getArticleByVacancy($id, true); //true : reload to get new article
                //getArticleByVacancy returns [] if not found. sendToMenu handles this
                $this->headerAndClose(204);
                break;


            default:
                $this->sendToMenu($menu);
                break;
        }
    }

    /**
     *
     */
    private function headerAndClose(int $code = 403): never
    {


        $app = $this->getApplication();
        $app->setHeader('status', $code);
        $app->sendHeaders();
        $app->close(); //does exit
        exit;
    }
    /**
     *
     */
    private function sendToMenu($menu, array $article = []): never
    {

        $relative = "index.php?Itemid={$menu->id}";
        if ($article) {
            $relative .= "&option=com_content&view=article&id={$article['id']}&catid={$article['catid']}";
        }
        $link     = Route::link('site', $relative, false, Route::TLS_FORCE, true);
        $this->getApplication()->redirect($link); //does exit
        exit;
    }


    private function checkApiKey(): bool
    {
        $client = $this->getApplication()->client ?? null;
        foreach ($client->headers as $key => $value) {
            if (strtolower($key) === 'x-api-key') {
                $rehashedKey = join('-', str_split(md5($this->getApplication()->get('secret') . $this->getHashKey()), 8));
                if ($value == $rehashedKey) {
                    return true;
                }

                return false;
            }
        }
        return false;
    }

    private function getIdFromUri(): int
    {
        $uri   = Uri::getInstance();
        $path  = trim((string) $uri->getPath(), '/');
        $parts = explode('/', $path);
        $last  = end($parts);
        if (! is_numeric($last)) {
            return 0;
        }
        return (int)$last;
    }

    private function unsetUser()
    {
        if ($this->didSetUser) {
            $user             = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById(0);
            $this->didSetUser = false;
            $this->getApplication()->getSession()->set('user', $user);
            $this->getApplication()->loadIdentity($user);
        }
    }
    private function getApplyFormHtml(int $id)
    {
        $language       = strtolower((string) $this->params->get('language', 'nl'));
        $environment    = $this->params->get('environment', 'DEV');
        $applicantToken = $this->params->get('applicant_token', '');
        return "<div id=\"ub-apply-form\"
data-api-token=\"{$applicantToken}\"
data-vacancy-id=\"{$id}\"
data-language=\"{$language}\"
data-environment=\"{$environment}\">
</div>";
    }
    private function getPortalHtml()
    {
        $language       = strtolower((string) $this->params->get('language', 'nl'));
        $environment    = $this->params->get('environment', 'DEV');
        $applicantToken = $this->params->get('applicant_token', '');
        return "<div id=\"ub-candidate-portal\"
data-api-token=\"{$applicantToken}\"
data-language=\"{$language}\"
data-environment=\"{$environment}\">
</div>";
    }


    private function taskUbeeo(ExecuteTaskEvent $event): int
    {

        $app = $this->getApplication();
        if (! ($app instanceof CMSApplication)) {
            $msg = Text::_("PLG_SYSTEM_UBEEO_NOT_CLI");
            $this->logTask($msg, 'error');
            $event->setResult(['error' => $msg]);
            //return OK, otherwise the task gets blocked
            return Status::OK;
        }

        try {
            $this->setUser();
            $this->logTask(Text::_("PLG_SYSTEM_UBEEO_LOG_TASK_START"), 'info');
            $this->processFeed();
            $this->logTask(Text::_("PLG_SYSTEM_UBEEO_LOG_TASK_END"), 'info');
        } catch (\RuntimeException $e) {
            $event->setResult(['error' => $e->getMessage()]);
            $this->unsetUser();
            return  Status::KNOCKOUT;
        }
        $this->unsetUser();
        return  Status::OK;
    }

    public static function getSubscribedEvents(): array
    {

        $events = [
            'onError'                => ['onError', Event\Priority::MAX],
            'onExtensionAfterSave'   => 'onExtensionAfterSave',
            'onTaskOptionsList'      => 'advertiseRoutines',
            'onExecuteTask'          => 'standardRoutineHandler',
            'onAjaxUbeeo'            => 'onAjaxUbeeo',
            'onContentAfterTitle'    => 'onContentAfterTitle',
            'onContentAfterDisplay'  => 'onContentAfterDisplay',
            'onContentBeforeDisplay' => 'onContentBeforeDisplay',
            'onContentPrepareForm'   => ['prepareForm', Event\Priority::MIN],
        ];

        return $events;
    }

    /**
     * Disables the save and apply buttons in the edit form by hidding them
     *
     * @param   PrepareFormEvent  $prepareFormEvent
     *
     * @since   25.52.7250
     */

    public function prepareForm(PrepareFormEvent $prepareFormEvent): void
    {

        $form    = $prepareFormEvent->getForm();
        $context = $form->getName();
        if ($context != 'com_content.article') {
            return;
        }

        $data    = $prepareFormEvent->getData();
        //niet in een edit formulier
        if (!$data) {
            return;
        }

        $searchCat = (int)$this->params->get('cat_id', 0);

        if (\is_array($data)) { //stupid joomla
            $contentCat = (int)($data['catid'] ?? 0);
        } else {
            $contentCat = (int)($data->catid ?? 0);
        }


        if ($contentCat != $searchCat) {
            return;
        }

        $app = $this->getApplication();
        $doc = $app->getDocument();
        if (empty($doc)) {
            //    return;
        }
        $wa  = $app->getDocument()->getWebAssetManager();
        $wa->addInlineStyle("
          div#toolbar-dropdown-save-group ,
        joomla-toolbar-button#toolbar-apply {
            display: none;
        }  
        ");
    }
    private function getFromRequest(): int
    {
        $input = $this->getApplication()->getInput();



        if ($input->get('option', '', 'CMD') != 'com_content') {
            return 0;
        }
        if ($input->get('view', '', 'CMD') != 'article') {
            return 0;
        }

        return $input->get('id', 0, 'INT');
    }

    private function display($context, $item, int $where = 0): string
    {
        $display  = $this->params->get('display', 3);
        $portal   = $this->params->get('portal', 3);
        $portalId = $this->params->get('portal_id', 0);
        if (
            !$display
            &&
            !$portal
            &&
            !$portalId
        ) {
            return '';
        }

        if ($context != 'com_content.article') {
            return '';
        }
        $item->id ??= 0;

        if ($item->id == $portalId) {
            if ($portal == $where) {
                $this->enqueueApplicantResources();
                return $this->getPortalHtml();
            }
            return '';
        }

        if ($display != $where) {
            return '';
        }
        //this saves a query, but getVacancyById would return nothing if the article is not in the right category
        if (isset($item->catid)) {
            if ($item->catid != $this->params->get('cat_id', 0)) {
                return '';
            }
        }

        if (empty($item->vacancy)) {
            if (!$item->id) {
                return '';
            }
            //alleen opzoeken als we in de juiste categorie zitten
            $vacancy = $this->getVacancyById($item->id);
        } else {
            $vacancy = $item->vacancy;
        }

        if (!$vacancy) {
            return '';
        }

        if (! is_numeric($vacancy)) {
            return '';
        }
        $this->enqueueApplicantResources();
        return $this->getApplyFormHtml($vacancy);
    }

    /**
     * @param bool $sync - if true, the resources are enqueued direclty. If false the resources are wrapped in a loaded script for asybcrous loading
     *
     * @since 25.44.7261
     */


    public function ubeeoPortalInsert(bool $head = true): string
    {
        $this->enqueueApplicantResources($head);
        return $this->getPortalHtml();
    }
    public function onContentAfterTitle(Content\AfterTitleEvent $event)
    {
        $event->addResult($this->display($event->getContext(), $event->getItem(), 1));
    }
    public function onContentAfterDisplay(Content\AfterDisplayEvent $event)
    {
        $event->addResult($this->display($event->getContext(), $event->getItem(), 3));
    }

    public function onContentBeforeDisplay(Content\BeforeDisplayEvent $event)
    {
        $event->addResult($this->display($event->getContext(), $event->getItem(), 2));
    }
    /**
     * @param int $id - thee article id
     * @param bool $sync - if true, the resources are enqueued direclty. If false the resources are wrapped in a loaded script for asybcrous loading
     *
     * @since 25.44.7261
     */

    public function ubeeoFormInsert(int $id = 0, int $vacancy = 0, bool $head = true): string
    {
        if (!$vacancy) {
            if (!$id) {
                $id = $this->getFromRequest();
            }
            if (!$id) {
                return '';
            }
            $vacancy = $this->getVacancyById($id);
        }

        if (!$vacancy) {
            return '';
        }

        if (! is_numeric($vacancy)) {
            return '';
        }
        $this->enqueueApplicantResources($head);
        return $this->getApplyFormHtml($vacancy);
    }

    public function onExtensionAfterSave(AfterSaveEvent $event): void
    {

        $table = $event->getItem();
        $type  = $table->get('type');
        if ($type != 'plugin') {
            return;
        }

        $folder = $table->get('folder');
        if ($folder != $this->_type) {
            return;
        }

        $element = $table->get('element');
        if ($element != $this->_name) {
            return;
        }
        $newParams    =  new Registry($table->get('params'));
        $currentCatId = $this->params->get('cat_id', 0);
        if ($currentCatId && $newParams->get('cat_id', 0) != $currentCatId) {
            $this->getApplication()->enqueueMessage(
                Text::_('PLG_SYSTEM_UBEEO_CATID_CHANGED'),
                'warning'
            );
        }

        $currentFieldGroupId = $this->params->get('fieldgroup_id', 0);
        if ($currentFieldGroupId && $newParams->get('fieldgroup_id', 0) != $currentFieldGroupId) {
            $this->getApplication()->enqueueMessage(
                Text::_('PLG_SYSTEM_UBEEO_FIELDGROUPID_CHANGED'),
                'warning'
            );
        }
        $this->params = $newParams; // the new config is only saved $this->params is the old one!
        try {
            $this->checkParams();
        } catch (\RuntimeException $e) {
            $this->getApplication()->enqueueMessage($e->getMessage(), 'error');
        }
    }
    private function processFeed()
    {
        $jobData = $this->retrieveVacancyFeed();
        $this->processVacancies($jobData);
        return $jobData;
    }

    private function processVacancies(object $jobData)
    {

        $vacancies        = $jobData->vacancies ?? [];
        $this->totalCount = \count($vacancies);
        if (! $this->totalCount) {
            return; #Lege feed, maar zou wel geldig moeten zijn.
        }
        foreach ($vacancies as $vacancie) {
            $this->processVacancie($vacancie);
        }
        $this->updateListfields(false);
        $this->processObsoleteVacancies();
    }
    /**
     *
     */

    private function processObsoleteVacancies()
    {

        $ids          = array_column($this->vacancyArticles, 'id');

        $articleModel =  $articleModel = $this->getModel('com_content', 'Article');
        $withObsolete = (int) $this->params->get('obsolete', 0);

        //1 - published
        //0 - unpublished
        //2 - archived
        //-2 - trashed
        //-99 - real delete
        switch ($withObsolete) {
            case 0:
            case 2:
            case -1:
            case -2:
                $articleModel->publish($ids, $withObsolete);
                break;
            case -99:
                $articleModel->delete($ids);
                break;
        }
    }
    /**
     * In hun WP plugin is 'text' leidend. hier is dat nu omgekeerd.
     * Als er textBlocks zijn wordt de text overschreven.
     *
     */
    private function getArticleBody($vacancie): object
    {


        $articleBody = $this->getByLanguage($vacancie);

        if (!empty($articleBody->textBlocks)) {
            $body = [];
            foreach ($articleBody->textBlocks as $block) {
                $body[] =  \sprintf('<!-- %s -->', $block->id);
                $text   = '';
                if ($block->title ?? '') {
                    $text = \sprintf(
                        '<%1$s>%2$s</%1$s>%3$s', // like in their WP plugin
                        $this->params->get('titlewrapper', 'h2'),
                        $block->title,
                        $block->text
                    );
                } else {
                    $text = $block->text ?? '';
                }

                if ($text) {
                    $body[] = \sprintf(
                        '<%1$s class="ub-text-item ub-text-item-%2$s">%3$s</%1$s>',
                        $this->params->get('textwrapper', 'div'),
                        $block->id,
                        $text
                    );
                }
            }
            $articleBody->text = join("\n", $body);
        }
        return $articleBody;
    }

    private function processVacancie(object $vacancie): int
    {

        if (! $vacancie->id ?? 0) {
            return 0;
        }


        $locationFields                      = $this->updateFieldsWithLocations($vacancie->locations ?? []);
        $publicationFields                   = $this->updateFieldsWithPublications($vacancie->publication ?? []);
        $templateFields                      = $this->updateFieldsWithSingle('Template', $vacancie->template ?? []);
        $departmentFields                    = $this->updateFieldsWithSingle('Department', $vacancie->department ?? []);

        $classificationFields                    = $this->updateFieldsWithClassifications($vacancie->classifications ?? []);

        $currentFields                    = array_merge($templateFields, $departmentFields, $classificationFields, $locationFields, $publicationFields);

        $vacancyFieldName                 = $this->getVacancyField();
        $currentFields[$vacancyFieldName] = $vacancie->id;
        $article                          = $this->getArticleByVacancy($vacancie->id);
        //remove from list. So what remains is obsolete.
        unset($this->vacancyArticles[$vacancie->id]);
        $catid        =  $this->params->get('cat_id', 0);
        $userId       = $this->params->get('user_id', 0);
        $newData      = $this->getArticleBody($vacancie);

        //Joomla sets the modified date, so can't use that one to sync.
        //So let's use  the checked_out_time for it
        //to avoid unneccesarly updates
        //the $vacancie->lastUpdated contains the timezone. So it should convert correctly.
        //using the checked_out_time has the benifit that if someone tries to change the article, the checked_out_time will be reset and the article will be updated.
        $lastUpdated  = new Date($vacancie->lastUpdated);
        //if the article is checked out, the date will not be the correct one anymore
        //if the checkout is cancelled the date will be null.
        //in both case an update is done to revert al possible changes
        $checkedOut     = $article['checked_out'] ?? 0;
        $currentUpdated = new Date(
            $checkedOut ?
                '2000-01-01 00:00:00' :
                $article['checked_out_time'] ?? '2000-01-01 00:00:00'
        );

        if ($lastUpdated <= $currentUpdated) {
            return $vacancie->id;
        }

        $newArticle = [
            'id'    => $article['id'] ?? 0,
            'catid' => $catid,
            'title' => $newData->title,
            'alias' => $article['alias'] ?? $this->generateNewTitle($catid, $newData->title),
            //vacancy does not have a created date. So we use the last updated
            'created'          => $article['created'] ?? $lastUpdated->toSql(),
            'publish_up'       => $article['publish_up'] ?? $lastUpdated->toSql(),
            'checked_out'      => null,
            'checked_out_time' => null,
            'created_by'       => $userId,
            'modified_by'      => $userId,
            'introtext'        => $newData->teaser ?? '',
            'metadesc'         => $newData->teaser ?? '',
            'fulltext'         => $newData->text ?? '',
            'state'            => 1,
            'access'           => 1,
            'language'         => '*',
            'com_fields'       => $currentFields,
        ];
        $this->updateCount++;

        $this->saveArticle($newArticle);


        return $vacancie->id;
    }

    /**
     * Method to get unique alias from a title
     * adpated from Joomlas AdminModel
     *
     * @param   integer  $categoryId  The id of the category.

     * @param   string   $title       The title.
     *
     * @return  string alias
     *
     * @since   25.52.7250
     */
    private function generateNewTitle($categoryId, $title)
    {
        $app = $this->getApplication();
        if ($app->get('unicodeslugs') == 1) {
            $alias = OutputFilter::stringUrlUnicodeSlug($title);
        } else {
            $alias = OutputFilter::stringURLSafe($title);
        }



        // Alter the title & alias
        $model      = $this->getModel('com_content', 'Article');
        $table      = $model->getTable();
        $aliasField = $table->getColumnAlias('alias');
        $catidField = $table->getColumnAlias('catid');

        while ($table->load([$aliasField => $alias, $catidField => $categoryId])) {
            $alias = StringHelper::increment($alias, 'dash');
        }

        return $alias;
    }


    private function updateFieldsWithSingle($field, $single)
    {
        if (!$single) {
            return; #Lege feed, maar zou wel geldig moeten zijn.
        }
        $currentFields                  = [];
        $fieldName                      = "single $field"; //avoid name collisions
        $fieldTableName                 = $this->getFieldTableName($fieldName);
        $content                        = (object)[
            'label'  => ucwords($field),
            'values' => [
                (object)[
                    'text' => $single->name,
                    'code' => $single->id,
                ],
            ],
        ];

        $currentFields[$fieldTableName] = $this->extractFields($fieldName, $content);
        return $currentFields;
    }
    private function updateFieldsWithPublications($publications)
    {
        //om de een of andee bizare reden is dit opens een object

        if (empty($publications)) {
            return; #Lege feed, maar zou wel geldig moeten zijn.
        }
        /*is altijd een enkel element. Maar zo is de return value consistent met de andere functies die velden bijwerken*/
        $currentFields = [];
        /*we unsetten en waarde, clone gebruiken */
        $publications = clone $publications;
        $field        = 'publication';
        $content      = (object)[
            'label'  => ucwords($field),
            'values' => [],

        ];

        if (($publications->displayInList ?? false) === true) {
            $content->values[] =  (object)[
                'text' => 'displayInList',
                'code' => 'displayInList',
            ];
        }
        unset($publications->displayInList);
        //$publications is een object maar daar kunnen we overheen met foreach
        foreach ($publications as $name => $publication) {
            //als het goed is staan alleen active vacatures in de feed
            // dus published zou nooit false moeten zijn
            //maar welliht dat internet == false en intranet == true wel voorkomt
            if ($publication->published) {
                $content->values[] =  (object)[
                    'text' => $name,
                    'code' => $name,
                ];
            }
        }

        $fieldTableName                 = $this->getFieldTableName($field);
        $currentFields[$fieldTableName] = $this->extractFields($field, $content);
        return $currentFields;
    }
    private function updateFieldsWithLocations($locations)
    {
        $currentFields = [];
        if (!\count($locations)) {
            return $currentFields; #Lege feed, maar zou wel geldig moeten zijn.
        }

        foreach ($locations as $location) {
            $field = 'location';
            //locations hebben geen language veld
            $content = (object)[
                'label'  => ucwords($field),
                'values' => [
                    (object)[
                        'text' => $location->name,
                        'code' => $location->id,
                    ],
                ],


            ];

            $fieldTableName                 = $this->getFieldTableName($field);
            $currentFields[$fieldTableName] = $this->extractFields($field, $content);
            foreach ($location as $fieldSub => $locationSub) {
                if (\is_object($locationSub)) {
                    foreach ($locationSub as $subfield => $value) {
                        $field = "{$fieldSub} {$subfield}";
                        //locations hebben geen language veld
                        $content = (object)[
                            'label'  => ucwords($field),
                            'values' => [
                                (object)[
                                    'text' => $value,
                                    'code' => $value,
                                ],
                            ],
                        ];
                        $this->getTextField($field);
                        $fieldTableName                 = $this->getFieldTableName($field);
                        $currentFields[$fieldTableName] = $this->extractFields($field, $content);
                    }
                }
            }
        }

        return $currentFields;
    }

    private function updateFieldsWithClassifications($classifications)
    {
        if (!\count($classifications)) {
            return; #Lege feed, maar zou wel geldig moeten zijn.
        }
        $currentFields = [];
        foreach ($classifications as $classification) {
            $content                        = $this->getByLanguage($classification);
            $fieldTableName                 = $this->getFieldTableName($classification->id);
            $currentFields[$fieldTableName] = $this->extractFields($classification->id, $content);
        }
        return $currentFields;
    }

    /**
     * creates the name (alias) for the field
     * uses the fieldgroup_id as prefix to avoid collisions
     * no the field group name as someone might change that.
     *
     *
     */

    private function getFieldTableName(string $type): string
    {
        $fieldGroupNamePrefix    = $this->params->get('fieldgroup_id', 0); //valided in form

        if (!$fieldGroupNamePrefix) {
            throw new \RuntimeException('Not a valid field group');
        }
        return OutputFilter::stringURLSafe("{$fieldGroupNamePrefix} {$type}"); // use the group name as prefix to avoid collisions
    }

    /**
     *
     * this will update the 'options' parameter in the field definition.
     */

    private function updateListfields(bool $append = false)
    {

        foreach ($this->extractedListfields as $id => $extractedOptions) {
            $fieldTableName = $this->getFieldTableName($id); // use the group name as prefix to avoid collisions
            $fieldTable     = $this->getFieldTable(['name' => $fieldTableName]);
            if ($fieldTable->type !== 'list') {
                continue;
            }
            $fieldParams    = json_decode($fieldTable->fieldparams, true);
            //dit voegt de bestaande velden toe. dit is nodig voor single PUT
            //voor een volledige import worden de velden helemaal ververst
            if ($append) {
                foreach ($fieldParams['options'] ?? [] as $option) {
                    $extractedOptions[] = $option;
                }
            }

            $uniqueOptions = [];
            //create unique option list
            foreach ($extractedOptions as $option) {
                $uniqueOptions["option{$option['value']}"] ??= $option;
            }

            $fieldParams['options'] = array_values($uniqueOptions);
            if ($fieldTable->fieldparams != json_encode($fieldParams)) {
                $fieldTable->save(
                    [
                        'fieldparams' => $fieldParams,
                    ]
                );
            }
        }
    }

    private function getTextField($name, $returnString = true): string| int
    {
        //create the field if it does not exists.

        $fieldTableName = $this->getFieldTableName($name);
        $fieldTable     = $this->getFieldTable(['name' => $fieldTableName]);
        if (!$fieldTable->id) {
            $fieldTable =   $this->createTextField($fieldTable, ucfirst($name), $fieldTableName);
        }

        return $returnString ? $fieldTableName : $fieldTable->id;
    }

    private function getVacancyField($returnString = true): string| int
    {
        //create the field if it does not exists.

        $fieldTableName = $this->getFieldTableName('vacancy-id');
        $fieldTable     = $this->getFieldTable(['name' => $fieldTableName]);
        if (!$fieldTable->id) {
            $fieldTable =   $this->createIntegerField($fieldTable, 'Vacancy ID', $fieldTableName);
        }

        return $returnString ? $fieldTableName : $fieldTable->id;
    }
    /**
     *
     * this return the field name of id for a given 'type'
     * it does not create the field
     * intended use is from teplate overrides
     * @param string $name - the name or alias of the field - Not the title.
     *
     */
    public function getFieldId(string $name): int
    {
        $fieldTableName = $this->getFieldTableName($name);
        $fieldTable     = $this->getFieldTable(['name' => $fieldTableName]);

        if (! $fieldTable) {
            $fieldTable     = $this->getFieldTable(
                ['name' => OutputFilter::stringURLSafe($name)]
            );
        }

        return $fieldTable->id ?? 0;
    }

    private function extractFields(string $id, array|object $content): array
    {

        //create the field if it does not exists.
        //do it here since we have $content for the label
        $fieldTableName = $this->getFieldTableName($id); // use the group name as prefix to avoid collisions
        $fieldTable     = $this->getFieldTable(['name' => $fieldTableName]);
        if (!$fieldTable->id) {
            $this->createListField($fieldTable, $content->label, $fieldTableName);
        }

        /**
         * Used for adding the fields to the article. There we only need the code
         */
        $currentListfields = [];
        $this->extractedListfields[$id] ??= [];
        foreach ($content->values as $value) {
            $text = trim($value->text, " \t\n\r\0\x0B\xc2\xa0");

            $option                           = ['name' => $text, 'value' => $value->code];
            $currentListfields[]              = $value->code;

            $this->extractedListfields[$id][] = $option;
        }

        return $currentListfields;
    }
    private function createListField($fieldTable, $title, $name)
    {
        $data = [
            'title'       => $title,
            'label'       => $title,
            'description' => 'imported by ubeeo plugin',
            'name'        => $name,
            'group_id'    => $this->params->get('fieldgroup_id', 0),
            'context'     => 'com_content.article',
            'type'        => 'list',
            'state'       => 1,
            'params'      => [
                'class'              => '',
                'label_class'        => '',
                'show_on'            => '',
                'showon'             => '',
                'render_class'       => '',
                'value_render_class' => '',
                'showlabel'          => '1',
                'label_render_class' => '',
                'display'            => 1, // set to null to hide.
                'prefix'             => '',
                'suffix'             => '',
                'layout'             => '',
                'display_readonly'   => '2',
                'searchindex'        => '0',
                'form_layout'        => "joomla.form.field.list-fancy-select",
            ],
            'fieldparams' => [
                'header'   => '',
                'multiple' => 1,
            ],
            'language' => '*', //todo?

        ];

        $fieldTable->save($data);
        $this->setFieldsCategory($fieldTable->id);
        return $fieldTable;
    }

    private function createIntegerField($fieldTable, $title, $name)
    {
        $data = [
            'title'       => $title,
            'label'       => $title,
            'description' => 'imported by ubeeo plugin',
            'name'        => $name,
            'group_id'    => $this->params->get('fieldgroup_id', 0),
            'context'     => 'com_content.article',
            'type'        => 'text',
            'state'       => 1,
            'params'      => [
                'hint'               => '',
                'class'              => '',
                'label_class'        => '',
                'show_on'            => '',
                'showon'             => '',
                'render_class'       => '',
                'value_render_class' => '',
                'showlabel'          => '1',
                'label_render_class' => '',
                'display'            => '1',
                'prefix'             => '',
                'suffix'             => '',
                'layout'             => '',
                'display_readonly'   => '2',
                'searchindex'        => '0',

            ],
            'fieldparams' => [
                'filter'    => 'integer',
                'maxlength' => 0,
            ],
            'language' => '*', //todo?

        ];

        $fieldTable->save($data);
        $this->setFieldsCategory($fieldTable->id);
        return $fieldTable;
    }

    private function createTextField($fieldTable, $title, $name)
    {
        $data = [
            'title'       => $title,
            'label'       => $title,
            'description' => 'imported by ubeeo plugin',
            'name'        => $name,
            'group_id'    => $this->params->get('fieldgroup_id', 0),
            'context'     => 'com_content.article',
            'type'        => 'text',
            'state'       => 1,
            'params'      => [
                'hint'               => '',
                'class'              => '',
                'label_class'        => '',
                'show_on'            => '',
                'showon'             => '',
                'render_class'       => '',
                'value_render_class' => '',
                'showlabel'          => '1',
                'label_render_class' => '',
                'display'            => '1',
                'prefix'             => '',
                'suffix'             => '',
                'layout'             => '',
                'display_readonly'   => '2',
                'searchindex'        => '0',

            ],
            'fieldparams' => [
                'filter'    => "\Joomla\CMS\Component\ComponentHelper::filterText",
                'maxlength' => 512,
            ],
            'language' => '*', //todo?

        ];

        $fieldTable->save($data);
        $this->setFieldsCategory($fieldTable->id);
        return $fieldTable;
    }

    private function getModel(string $component = 'com_blc', string $name = 'Link', string $prefix = 'Administrator', array $config = ['ignore_request' => true]): mixed
    {
        $mvcFactory = $this->getApplication()->bootComponent($component)->getMVCFactory();
        return $mvcFactory->createModel($name, $prefix, $config);
    }

    private function getByLanguage(object $data)
    {
        $language = strtoupper((string) $this->params->get('language', 'NL'));
        return $data->contentByLanguage->{$language} ?? new \stdClass();
    }

    private function saveArticle($article)
    {
        $articleModel = $this->getModel('com_content', 'Article');
        if (!$articleModel->save($article)) {
            throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_UBEEO_ARTCLE_SAVE_FAILED', $articleModel->getError()));
        }
    }

    private function getBaseQuery(): DatabaseQuery
    {
        $catid          =  $this->params->get('cat_id', 0);
        $vacancyFieldId = $this->getVacancyField(false);
        $db             = $this->getDatabase();
        $query          = $db->createQuery();
        $query->select($db->quoteName('fv.value', 'vacancy'))
            ->from($db->quoteName('#__content', 'c'))
            ->join('INNER', $db->quoteName('#__fields_values', 'fv'), '(' . $db->quoteName('fv.field_id') . ' = :fieldid AND ' . $db->quoteName('fv.item_id') . ' = ' . $db->quoteName('c.id') . ')')
            ->bind(':fieldid', $vacancyFieldId)
            ->where($db->quoteName('catid') . '= :catid')
            ->bind(':catid', $catid);
        return $query;
    }

    private function getVacancyById(int $id): string
    {
        $query = $this->getBaseQuery();
        $db    = $this->getDatabase();
        $query->where($db->quoteName('id') . '= :id')
            ->bind(':id', $id);
        $db->setquery($query);
        return $db->loadResult() ?? '';
    }

    private function getVacancyArticles(bool $reload = false)
    {
        if ($reload || !$this->vacancyArticles) {
            $query = $this->getBaseQuery();
            $db    = $this->getDatabase();

            $query->select(
                [
                    'c.id',
                    'c.alias',
                    'c.created',
                    'c.checked_out_time',
                    'c.checked_out',
                    'c.publish_up',

                ]
            )
                ->select($db->quoteName('fv.value', 'vacancy'));
            $db->setquery($query);
            $this->vacancyArticles = $db->loadAssocList('vacancy');
        }
    }
    /**
     * get articles with given vacancy
     * This loads all vacancy content first.
     * This fetches all articles at once. Save a lot of queries.
     * If there are a huge amount of articles, like hundreds, their might be a need for a different solution
     */

    private function getArticleByVacancy(int $vacancy, bool $reload = false): array
    {
        $this->getVacancyArticles($reload);
        return $this->vacancyArticles[$vacancy] ?? [];
    }

    private function getCache(): ?OutputController
    {

        $lifeTime = $this->params->get('cache_time', 60);
        if (!$lifeTime) {
            return null;
        }
        $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
            ->createCacheController('output', ['defaultgroup' => 'ubeeo-feeds']);
        $cache->setLifeTime($lifeTime);
        $cache->setCaching(true);
        return $cache;
    }

    /**
     *
     * Get's the field Table for the given pks.
     * the model  only loads by id
     *
     */

    private function getFieldTable(array $pks): FieldTables\FieldTable
    {
        static $cache = [];
        $pks['context']  ??= 'com_content.article';
        $pks['group_id'] ??= $this->params->get('fieldgroup_id', 0); //valided in form
        $id = md5(json_encode($pks));
        if (!isset($cache[$id])) {
            $fieldTable      = new FieldTables\FieldTable($this->getDatabase());
            $fieldTable->load($pks);
            $cache[$id] =  $fieldTable;
        }
        return $cache[$id];
    }

    private function setFieldsCategory($id)
    {
        //this is onlyy used for new fields so table should not have an entry
        //cleanup not needed
        $db    = $this->getDatabase();
        $catId = $this->params->get('cat_id', 0);
        // Inset  categorie
        $tuple              = new \stdClass();
        $tuple->field_id    = $id;
        $tuple->category_id = $catId;
        $db->insertObject('#__fields_categories', $tuple);
    }
    /**
     * @throws RuntimeException
     * @return true
     */

    private function checkParams(): true
    {
        if (!$this->params->get('cat_id', 0)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_CATEGORY_NOT_SET'));
        }

        if (!$this->params->get('fieldgroup_id', 0)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_FIELDGROUP_NOT_SET'));
        }

        if (empty($this->params->get('applicant_token', null))) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_APPLICANT_TOKEN_NOT_SET'));
        }

        if (empty($this->params->get('vacancy_feed_token', null))) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_VACANCY_FEED_TOKEN_NOT_SET'));
        }
        if (empty($this->getHashKey())) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_UBEEO_HASH_NOT_SET'));
        }
        $this->checkAuthorise($this->params->get('user_id', 0));
        return true;
    }

    private function retrieveVacancyFeed(): ?object
    {
        if (!$this->checkParams()) {
            return null;
        }

        $url = $this->getVacancyFeedUrl();

        return $this->getFeed($url);
    }

    private function getFeed(string $url): ?object
    {
        $cache = $this->getCache();

        if (!$cache) {
            return  $this->getRemoteJson($url);
        }

        $cacheKey =  md5($url);
        if ($cache->contains($cacheKey)) {
            $json = $cache->get($cacheKey);
        } else {
            $json = $this->getRemoteJson($url);

            $cache->store($json, $cacheKey);
        }

        return $json;
    }

    private function getRemoteJson(string $url): ?object
    {
        $json = null;

        $response = HttpFactory::getHttp()->get($url);
        if (! $response) {
            throw new \RuntimeException('No response');
        }
        if (! $response->body) {
            throw new \RuntimeException('No response body');
        }
        $json = json_decode((string) $response->body);
        if (!$json) {
            throw new \RuntimeException('No response json');
        }

        return $json;
    }

    private function getVacancyFeedUrl(): string
    {
        $vacancy_feed_token = $this->params->get('vacancy_feed_token', '');
        $base_host          = self::getBaseHost();
        return empty($vacancy_feed_token) ? "" : "https://{$base_host}/feeds/vacancies?api-token={$vacancy_feed_token}";
    }

    private function getBaseHost(): string
    {
        return match ($this->params->get('environment', 'DEV')) {
            'TEST'  => 'api.acc.ats-platform.com',
            'DEV'   => 'api.dev.ats-platform.com',
            default => 'api.ats-platform.com',
        };
    }

    private function getApplicantResourceBaseUrl(): string
    {
        return match ($this->params->get('environment', 'DEV')) {
            'DEV'   => 'https://applicant.dev.ats-platform.com/static/',
            'TEST'  => 'https://applicant.acc.ats-platform.com/static/',
            default => 'https://applicant.ats-platform.com/static/',
        };
    }
    /**
     * @param bool $head - if true, the resources are enqueued in the <head> of the page. If false the resources are wrapped in an inline loading script to allow loading on demand
     *
     *
     */
    private function enqueueApplicantResources($head = true): void
    {
        $app               = $this->getApplication();
        $resource_base_url = $this->getApplicantResourceBaseUrl();
        $js_file_name      = "ats-applicant-ui.js";
        $css_file_name     = "ats-applicant-ui.css";

        $applicant_javascript_src = "{$resource_base_url}{$js_file_name}";
        $applicant_stylesheet_src = "{$resource_base_url}{$css_file_name}";

        $wa = $app->getDocument()->getWebAssetManager();

        if ($head) {
            $wa->registerAndUseScript('applicantui-script', $applicant_javascript_src, [], ['defer' => 'true'], []);
            $wa->registerAndUseStyle('applicantui-css', $applicant_stylesheet_src, [], ['defer' => 'true'], []);
        } else {
            $wa->addInlineScript("
                function loadUbeeoResources() {
                    let s = document.createElement('script');
                    s.type = 'text/javascript';
                    s.src = '$applicant_javascript_src';
                    document.getElementsByTagName('head')[0].appendChild(s);
                   
                    let l = document.createElement('link');
                    l.setAttribute('rel', 'stylesheet');
                    l.setAttribute('href', '$applicant_stylesheet_src');
                    document.getElementsByTagName('head')[0].appendChild(l);
                }
            ");
        }
    }
}
