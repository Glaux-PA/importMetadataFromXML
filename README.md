Import metadata from XML
Version for OJS 3.3.X
This plugin load publcation metadata from a XML galley.


## Instructions
### Plugin Installation Guide for OJS

You can install this plugin in two ways:

#### 1. Upload via the OJS Web Interface
- Go to the **Dashboard** > **Website Settings** > **Plugins**.
- Click on **Upload a New Plugin**.
- Select the plugin `.tar.gz` or `.zip` archive and upload it.
- Once installed, make sure to **enable** the plugin.

#### 2. Manual Installation
- Upload or extract the plugin folder into the appropriate directory:
  - ojs/plugins/generic
- Activate plugin from plugin from "Website -> Plugins"

### Plugin Usage
* In the submission desired, add galley
    - ![Add galley](doc/img/submission_add_galley.png)
* Upload xml for import metadata
    - ![Xml galley](doc/img/submission_galley_add_xml.png)
* Apply "Import metadata"
    - ![Import metadata](doc/img/submission_import_metadata_button.png)
* Review metadata added, title, description, colaborators, references, etc...




## Improvements and Revisions
* Doesn't retrieve emails of authors/collaborators → Improvement
* Keywords, insert in all languages → Improvement
* JEL Code → A field specifically inserted for certain journals → Improvement
** Retrieve it as a keyword → Improvement
** Insert it in all languages
* Support/Funding Agencies → Improvement
** Only in one language → Should appear in all available languages → Improvement
* Abstract: Review if text formatting (bold, etc.) is correct → Improvement
** Style, bold, italics are important, both in the title and body text
** Occasionally, there may be links to references, but in that case, the link should not be included
* Collaborators → Improvement
* References: Inserted in a format that seems incorrect → Improvement
** A function has been added to remove unwanted spaces in the text
* Add security so that the button does not appear based on the user's role → Improvement
* Titles and subtitles: Manage secondary languages (trans-titles) or keep the default behavior → Improvement
* DOI: Not being registered
* Code Refactor and Translations
