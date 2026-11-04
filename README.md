# Padstone Mason (Wildly Experimental)

Control-panel chat for entries the current user can save. It edits the fields on that entry's layout, including structure fields such as the hero and Content Designer (Matrix by default; Neo via the optional [padstone-mason-neo](https://github.com/imarc/padstone-mason-neo) companion) when they are present. New entries can be created only in the sections listed in settings. If a request does not name one allowed section and one entry type on it, Mason asks which to use and shows the sections and entry types it knows. Edits go to a named draft called **Mason**. A second chat on the same entry gets the next numbered draft, such as **Mason 2**. Nothing goes live until someone publishes that draft.

## Neo Content Designer

Sites that still use Spicy Web Neo for Content Designer should also install the companion package [`imarc/craft-padstone-mason-neo`](https://github.com/imarc/padstone-mason-neo) (Neo `^5.2`). Core Mason stays Matrix-only and does not require Neo. See that repository’s README for install order and top-level v1 limits.

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
6. Choose the **Image upload volume** and optional folder path (default `mason`). Uploaded and URL-imported images land there. Users still need Craft permission to save assets in that volume.
7. Grant **Padstone Mason → Use Ask Mason**. Admins already have it. Settings and the usage report stay admin-only.

Turn **Enabled** off to hide the assistant. The settings screen stays available.

## Use

- On an entry edit screen for an entry you can save, open **Ask Mason**. The slideout lists that entry’s chats, shows the selected transcript, a preview when the section has one, and a link to that chat’s draft.
- **Padstone Mason → Ask Mason** lists every chat you own and can start a new one with no entry open. The first created page becomes that conversation’s draft. The same chat can keep editing that draft, or attach itself to an existing entry you can save. That attachment starts a Mason draft of the existing entry.
- **New chat** starts an empty transcript. On an entry, it also creates a separate draft so chats do not edit each other.
- **Delete** removes the chat and its messages. The draft stays in place.
- **Publish** applies the draft. Craft’s own view, save, and create permissions still apply.
- **Unpublish** disables the live entry when you ask. The chat stays bound to it.
- **Delete** moves the entry to the trash when you ask, and clears that chat’s binding. Craft’s delete permission still applies.
- **Padstone Mason → Usage** (admins) totals prompt and completion tokens by user, provider, and model for a date range. Rows are written only when the provider reports usage. Message text and API keys are not stored.
- **Attach image** uploads files into the configured volume and links them to the chat. Pasted image URLs in a message are downloaded the same way. The assistant sees those asset ids and can place them in Assets fields on any number of blocks. It can also call `import_asset` for a new URL later in the chat, `list_conversation_assets` to list linked images, and `update_asset_meta` for title/alt.

Each person only sees their own chats. The assistant does not read unsaved changes in the entry form.

## What it can edit

The fields on the entry's layout. Structure fields, including Content Designer and the hero when those fields exist, can take their existing block types, including nested Matrix fields such as columns and cards. Other writable layout fields, such as the breadcrumb title, can be updated on the entry. Writable fields are plain text, CKEditor, lightswitch, dropdown, link, regex, and existing assets or entries by id (including conversation uploads). Link fields accept a URL string or a typed object (`url`, `entry`, `asset`, `email`). Relation writes must use elements allowed by the field's sources.

Orientation and multi-block recipes use `summarize_page` (compact structure outline with min/max and short text signals) and `apply_blocks` (ordered add/update/remove/reorder ops in one call). `apply_blocks` stops on the first failure; earlier successful steps stay saved and the result reports each step. Single edits still use the atomic block tools.

`find_elements` searches entries and assets with filters (section, entry type, status, site, volume, folder). Prefer `list_conversation_assets` for images already in the chat.

A shared section is a relation to an existing shared block. Inner blocks are not copied.

## Tools

Mason picks these from the chat. Assistant replies show a **Tools used** footer when any ran. Sample prompts:

| Tool | What it does | Sample prompt |
|------|----------------|---------------|
| `describe_schema` | Lists writable fields and block types for an entry type | “What block types can I add on a Basic Page?” |
| `summarize_page` | Compact outline of Matrices, block ids/types, text signals, min/max | “What’s on this page right now?” |
| `read_layout` | Full title, scalars, and block field values (with atMin/atMax) | “Show me the full field values on the first rich text block.” |
| `create_page` | Creates an unpublished draft in an allowed section | “Create a Basic Page titled Contact in the pages section.” |
| `apply_blocks` | Ordered add/update/remove/reorder in one call; stops on first failure | “Add rich text sections titled Intro and Details with short paragraphs.” |
| `add_block` | Adds one structure block | “Add a rich text block at the top with headline Welcome.” |
| `update_block` | Updates fields on one block (use when a structure field is at max) | “Change the hero headline to Welcome back.” |
| `reorder_blocks` | Reorders sibling blocks | “Move the Details block above Intro.” |
| `remove_block` | Removes one block (respects min counts) | “Remove the Details rich text block.” |
| `update_entry` | Updates draft title, slug, parent, or scalar layout fields | “Set the breadcrumb title to About and turn breadcrumbs off.” |
| `find_elements` | Finds entries/assets with filters (section, type, status, volume, folder) | “Find shared blocks named like Footer.” |
| `rebind_entry` | Binds the chat to an existing entry (starts a Mason draft on edit) | “Edit the About page instead of this one.” |
| `list_conversation_assets` | Lists images already linked to this chat | “What images are linked to this chat?” |
| `update_asset_meta` | Sets asset title and/or alt | “Set alt text on the attached image to Team photo.” |
| `import_asset` | Downloads an image URL into the configured volume and links it | “Import https://example.com/hero.png and put it on the logos field.” |
| `publish_draft` | Publishes the bound Mason draft | “Publish this draft.” |
| `unpublish_entry` | Disables the live entry (explicit ask only) | “Unpublish this page.” |
| `delete_entry` | Trashes the entry and clears the binding (explicit ask only) | “Delete this entry.” |

Refused (not available): `create_block_type`, `apply_project_config`, `mcp`, `upload_asset` (use chat attach or `import_asset`).

## What it will not do

- Invent a block type, change project config, or put raw file bytes into field values.
- Upload files through the model (`upload_asset` is refused). Chat attach and `import_asset` remain the ingest paths.
- Write SEOmatic, code, or Pickture fields. Those are skipped and the rest of the update can still save.
- Drop a nested Matrix field below its minimum or above its maximum. A two-column section needs both columns in the same save.
- Publish, or edit an entry the current user cannot save.

Uninstalling the plugin leaves assistant drafts in place.
