# Ubeeo ATS Joomla

This is a Joomla plugin to import Ubeeo vacancies into Joomla Articles. **Classifications** are imported as Joomla Fields. After import, the vacancies are no different from other Joomla articles.

The plugin supports:
 * The pull method, where a cron job periodically imports all vacancies
 * The push method, where individual vacancies are updated using PUT and DELETE methods.

Currently, the plugin working for the Dutch Language, if there is demand the i18n will be extended.

Different flavors of the Ubeeo vacancies might require adaptations.

The required application form and customer portal require a custom  article layout.

## Filtering fields
filtering on fields can be accomplished with a component like [Jfilters](https://blue-coder.com/jfilters).
