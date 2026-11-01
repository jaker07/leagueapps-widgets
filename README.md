# LeagueApps Widgets

A WordPress plugin for showing LeagueApps data on your site. Each widget is available as both a **shortcode** and a **block**.

## Installation

**Requirements:** WordPress 6.1 or later and PHP 7.4 or later.

### Option 1: Upload a ZIP in the WordPress admin (easiest)

1. Make a ZIP of the plugin:
   - **From GitHub:** on the repository page, click **Code → Download ZIP**. If the repository is private, you need to be signed in to GitHub.
   - **From a local clone** (this gives a cleaner ZIP without development files):

     ```bash
     git archive --format=zip --prefix=leagueapps-widgets/ -o leagueapps-widgets.zip main
     ```
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

A ZIP downloaded from GitHub unpacks to a folder named `leagueapps-widgets-main`. That works fine. WordPress identifies the plugin by its header, not the folder name.

### Option 2: Copy the files to the server

1. Upload the plugin folder to `wp-content/plugins/leagueapps-widgets/` over SFTP or FTP, or with your host's file manager. `leagueapps-widgets.php` must be directly inside that folder.
2. In WordPress, go to **Plugins** and click **Activate** under **LeagueApps Widgets**.

### Option 3: WP-CLI

If you have shell access to the server:

```bash
wp plugin install /path/to/leagueapps-widgets.zip --activate
```

### Updating

The plugin doesn't update itself from WordPress.org. To install a new version, upload the new ZIP through **Plugins → Add New Plugin → Upload Plugin** and choose **Replace current with uploaded** when WordPress asks. Your settings are kept. Deleting the plugin from the Plugins screen removes its settings.

## Setup

1. Install and activate the plugin (see [Installation](#installation)).
2. Go to **Settings → LeagueApps** and enter your LeagueApps **Site ID** and **public API key**. The key is sent in the `la-api-key` header to `https://public.leagueapps.io`.
3. API responses are cached as transients. The default is 15 minutes. You can change that and clear the cache on the same settings page.

### More than one LeagueApps site

Some organizations have a separate LeagueApps site for each sport. To show data from several of them:

1. On **Settings → LeagueApps**, click **Add site** for each extra site and fill in a **label** (e.g. "Hockey"), its **site ID** and its **API key**. The **slug** is filled in from the label if you leave it blank (e.g. `hockey`).
2. Pick the site for each widget:
   - **Block:** choose it in the **LeagueApps site** dropdown in the block sidebar. The dropdown only appears once you have more than one site.
   - **Shortcode:** add `site="slug"`, e.g. `[leagueapps_standings sport="Hockey" site="hockey"]`.

The first site in the list is the default, and widgets without a `site` use it. If you only have one LeagueApps site, nothing changes for you. If you're upgrading from an earlier version, your existing site becomes the default automatically. Widgets store the slug, so you can change a site's ID or API key without editing any pages. Changing a slug, though, breaks widgets that use the old one. If a widget names a site that doesn't exist, administrators see an error and visitors see nothing.

## Widgets

### Standings

```
[leagueapps_standings sport="Soccer"]
[leagueapps_standings program_id="123456" ranking="points" show_scores="false"]
[leagueapps_standings sport="Hockey" site="hockey"]
```

Block: **LeagueApps Standings** (Widgets category). Its sidebar has the same options, with **Sport** and **Program** as dropdowns (see [Choosing a sport or program in the block editor](#choosing-a-sport-or-program-in-the-block-editor)).

| Attribute     | Default   | Notes |
|---------------|-----------|-------|
| `site`        | default site | Slug of a site from Settings → LeagueApps. Every widget has this option. |
| `sport`       | —         | Matches the program's sport in LeagueApps (case-insensitive). Shows every current program in that sport. |
| `program_id`  | —         | Show one program instead. |
| `ranking`     | `win_pct` | `win_pct` or `points` (3 for a win, 1 for a tie). |
| `show_ties`   | `true`    | Shows the T column. |
| `show_scores` | `true`    | Shows the PF / PA / Diff columns. |
| `title`       | program name | Overrides the heading. |

**How standings are calculated:** the LeagueApps Public API doesn't document a standings endpoint. Standings are worked out from completed games in `/v1/sites/{siteId}/programs/{programId}/schedule`, and teams come from `/programs/{programId}/teams`. The code tries several common names for each response field. If your API responses use different names, adjust the mapping with the `leagueapps_widgets_game_fields` filter (see `includes/class-schedule-data.php`). The schedule widget uses the same mapping.

### Schedule

```
[leagueapps_schedule program_id="123456"]
[leagueapps_schedule program_id="123456" show="upcoming" limit="5"]
[leagueapps_schedule program_id="123456" team="Lions" show_location="false"]
[leagueapps_schedule program_id="123456" site="hockey"]
```

Block: **LeagueApps Schedule** (Widgets category). Its sidebar has the same options, with **Program** as a dropdown (see [Choosing a sport or program in the block editor](#choosing-a-sport-or-program-in-the-block-editor)).

| Attribute       | Default      | Notes |
|-----------------|--------------|-------|
| `site`          | default site | Slug of a site from Settings → LeagueApps. |
| `program_id`    | —            | Required. The program whose schedule to show. |
| `show`          | `all`        | `all` (soonest first), `upcoming` (soonest first) or `past` (most recent first). A game counts as past once it has a score or its start time has passed. |
| `limit`         | `0`          | Maximum number of games. `0` shows all of them. |
| `team`          | —            | Only games involving this team, by name (case-insensitive) or team ID. That team's name is shown in bold. |
| `show_location` | `true`       | Shows the location and field/court. |
| `show_results`  | `true`       | Shows scores, or "Canceled" / "Postponed". |
| `title`         | program name | Overrides the heading. |

Games are grouped by day. Dates and times use the time zone, date format and time format from **Settings → General**. Games without a date are listed last under "Date to be announced". Canceled games are crossed out. Schedule entries without teams, such as a photo day, show their name instead of a matchup.

The layout adapts to the space it's in. In narrow spaces like phones and sidebars, the location moves under the matchup. In very narrow spaces, each game is stacked.

### Choosing a sport or program in the block editor

In both blocks, **Program** is a dropdown of the selected site's current programs, labelled with their sport (e.g. "Soccer: U10 Fall Soccer"). In the standings block, **Sport** is a dropdown of the sports those programs are in.

- The lists come from the site chosen in **LeagueApps site** on that block. Each block can use a different site.
- Changing a block's site reloads the lists for the new site and clears the selected sport and program, since they belong to one site.
- In the standings block, choosing a sport narrows the program list to that sport. If the selected program is in a different sport, it's cleared.
- A sport saved in different capitalization (e.g. `soccer` from a shortcode) still shows as selected, and nothing is rewritten.
- For a sport with no current programs, choose **Other sport (type it)…**. The standings will be empty until a program in that sport is current.
- Each site's list is fetched once per editor session and shared by every block and both dropdowns. On the server it's cached like other API responses.
- For a program that isn't listed, such as a past season, choose **Other program (enter ID)…** and type its ID. A saved block whose program isn't in the current list opens with the ID field showing.
- If the list can't be loaded (for example, a wrong API key), the sidebar shows the error and plain text fields instead.

The list comes from `GET /wp-json/leagueapps-widgets/v1/programs?site={slug}`, which is only available to logged-in users who can edit posts.

## Bold Builder

If [Bold Builder](https://wordpress.org/plugins/bold-page-builder/) is active, every LeagueApps widget is also a Bold Builder element. Nothing needs to be set up.

1. In Bold Builder's back-end or front-end editor, add an element to a column and pick **LeagueApps Schedule** or **LeagueApps Standings**. Searching "LeagueApps" in the element list finds both.
2. Fill in the settings. The **General** tab has the site, program, sport and other options. The **Display** tab has the show/hide options.

Differences from the block editor:

- **LeagueApps site** is a dropdown of your configured sites. It only appears once you have more than one.
- **Program ID** and **Sport** are typed, because Bold Builder's dropdowns can't change when another field changes. Use the program's ID number and the sport name as it appears in LeagueApps.
- Show/hide options are **Yes/No** dropdowns rather than checkboxes. A Bold Builder checkbox saves nothing when unchecked, so it couldn't turn off an option that defaults to on.

Bold Builder saves the same `[leagueapps_*]` shortcodes used everywhere else, so pages keep working if you stop using it. In its front-end editor, our elements can be selected, edited with a live preview, and saved like its own elements.

Developers can adjust the elements with the `leagueapps_widgets_bold_builder_element` and `leagueapps_widgets_bold_builder_params` filters.

## Adding a widget

1. Extend `LeagueApps_Widgets\Widget`. Implement `get_id()`, `get_title()`, `get_attributes()` (a schema) and `render( $atts )`.
2. Register it in `Plugin::__construct()`, or from another plugin:

```php
add_action( 'leagueapps_widgets_register', function ( $registry, $plugin ) {
    $registry->register( new My_Schedule_Widget() );
}, 10, 2 );
```

You get the shortcode `[leagueapps_{id}]`, the block `leagueapps-widgets/{id}` and, when Bold Builder is active, a Bold Builder element automatically. The block's sidebar controls are generated from the attribute schema, so no JavaScript is needed. Every widget also gets the `site` option automatically, so don't declare it yourself. To give a string attribute one of these dropdowns, add `'control' => 'program'` or `'control' => 'sport'` (and optionally `'empty_label'`) to its schema entry. Add `'filter_by' => 'sport'` to a program control to narrow it by a sport attribute.

Inside `render()`, get the API client for the widget's chosen site and use `site_get( 'path' )` for any `/v1/sites/{siteId}/…` endpoint:

```php
public function render( array $atts ) {
    $api = $this->api( $atts );
    if ( is_wp_error( $api ) ) {
        return $this->render_error( $api );
    }
    $data = $api->site_get( 'locations' );
    // …
}
```

Outside a widget, `LeagueApps_Widgets\Plugin::instance()->api( 'hockey' )` returns the client for a site by slug (`''` for the default).

## Hooks

- `leagueapps_widgets_register` (action): register widgets.
- `leagueapps_widgets_api_base_url`: override the API host.
- `leagueapps_widgets_shortcode_output`: a widget's shortcode HTML, after rendering (used to add Bold Builder's front-end editor markup).
- `leagueapps_widgets_bold_builder_element`, `leagueapps_widgets_bold_builder_params`: the Bold Builder element definition and its settings fields.
- `leagueapps_widgets_programs`: the normalized program list (`id`, `name`, `sport`) for a site, used by both widgets and the editor dropdown.
- `leagueapps_widgets_game_fields`: response field names for games and teams (standings and schedule).
- `leagueapps_widgets_excluded_game_states`: game states that are ignored.
- `leagueapps_widgets_standings_programs`, `leagueapps_widgets_standings_rows`, `leagueapps_widgets_standings_columns`: change the standings output.
- `leagueapps_widgets_schedule_games`: change which games the schedule shows, after filtering, sorting and the limit.
- `leagueapps_widgets_schedule_date_format`: the date format for the schedule's day headings (default `l, ` plus the site's date format).

## Development

In Claude Code cloud sessions, the `.claude/settings.json` SessionStart hook runs `wp-start`. It starts a local WordPress at http://localhost:8080 (admin/admin) with this plugin linked and active.
