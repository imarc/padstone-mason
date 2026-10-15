# Padstone Mason (Wildly Experimental)

Control-panel chat for entries that use the Content Designer field (`contentDesigner`). It can also edit the other fields on that entry’s layout, including the hero. Edits go to a named draft called **Mason**. A second chat on the same entry gets the next numbered draft, such as **Mason 2**. Nothing goes live until someone publishes that draft.

## Setup

1. Require it from the private repo [imarc/padstone-mason](https://github.com/imarc/padstone-mason). Composer needs access to that repo. There is no tagged release yet, so require the `main` branch.

   ```
   composer config repositories.padstone-mason vcs https://github.com/imarc/padstone-mason.git
   composer require imarc/craft-padstone-mason:dev-main
   ```

2. Install the plugin (`padstone-mason`).
3. Put a key in the environment. It is read at request time and is not stored in settings, the database, or project config.

   ```
   OPENAI_API_KEY=
   ANTHROPIC_API_KEY=
   ```

4. In **Settings → Plugins → Padstone Mason**, choose the provider and model. Defaults are OpenAI and `gpt-4.1`. For Anthropic, set the model as well (for example `claude-sonnet-4-5`).
5. List the section handles the assistant may create. The default is `pages`. An empty list blocks new pages and still allows edits to an existing entry.
6. Grant **Padstone Mason → Use Ask Mason**. Admins already have it. Settings and the usage report stay admin-only.

Turn **Enabled** off to hide the assistant. The settings screen stays available.

## Use

- On an entry edit screen that includes Content Designer, open **Ask Mason**. The slideout lists that entry’s chats, shows the selected transcript, a preview when the section has one, and a link to that chat’s draft.
- **Padstone Mason → Ask Mason** lists every chat you own and can start a new one with no entry open. The first created page becomes that conversation’s draft. The same chat can keep editing that draft.
- **New chat** starts an empty transcript. On an entry, it also creates a separate draft so chats do not edit each other.
- **Delete** removes the chat and its messages. The draft stays in place.
- **Publish** applies the draft. Craft’s own view, save, and create permissions still apply.
- **Padstone Mason → Usage** (admins) totals prompt and completion tokens by user, provider, and model for a date range. Rows are written only when the provider reports usage. Message text and API keys are not stored.

Each person only sees their own chats. The assistant does not read unsaved changes in the entry form.

## What it can edit

Block types already on Content Designer, including nested Matrix fields such as columns and cards. The page hero is a Matrix field on the entry layout (`hero`), usually one Standard Hero block, and is edited the same way. Other writable layout fields, such as the breadcrumb title, can be updated on the entry. Writable fields are plain text, CKEditor, lightswitch, dropdown, link, regex, and existing assets or entries by id.

A shared section is a relation to an existing shared block. Inner blocks are not copied.

## What it will not do

- Invent a block type, change project config, or upload a file.
- Write SEOmatic, code, or Pickture fields. Those are skipped and the rest of the update can still save.
- Drop a nested Matrix field below its minimum or above its maximum. A two-column section needs both columns in the same save.
- Publish, or edit an entry the current user cannot save.

Uninstalling the plugin leaves assistant drafts in place.
