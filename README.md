Aegir Deploy
=============

This module improves the UI for selecting the Aegir platform deployment strategy by cleaning up deployment strategies on the platform create/edit form.

Installation
------------

As this module ships with core Aegir, and is included in [Golden Contrib](https://docs.aegirproject.org/develop/repositories/#golden-contrib), simply navigate to *Hosting » Aegir Deployment*, and ensure that at least one of those modules is enabled.  They're all submodules that depend on this one.

Using it
--------

Start by navigating to Platforms -> Add Platform, and then entering the name of the new platform you'd like to create.

Then...

### None (not managed by Aegir)

Select this option to create a platform not managed by Aegir, and then submit the form.

### Deploy a Composer project from a Packagist repository

This option is for provisioning a Composer-based platform from [Packagist](https://packagist.org/).

Select the *Deploy a Composer project from a Packagist repository* option, fill in details, and then submit the form.

**Note:** This activity will recalculate all dependencies, and thus, it is very resource-intensive. It requires a miminum of 4G of RAM; we advise against performing this activity in production.

### Deploy a Composer project from a Git repository

This option is for provisioning a Composer-based platform from a Git repository, where your (presumably) custom `composer.json` is maintained.

Select the *Deploy a Composer project from a Git repository* option, fill in details, and then submit the form.

### Deploy from Git repository (not managed by Composer)

This option is for provisioning a non-Composer-based platform from a Git repository, where all of your code lives.

Select the *Deploy from Git repository (not managed by Composer)* option, fill in details, and then submit the form.

### Deploy from Drush makefile

This option is for provisioning a Drush-makefile-based platform.

Select the *Deploy from Drush makefile* option, fill in details, and then submit the form.
