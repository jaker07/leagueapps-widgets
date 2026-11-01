# LeagueApps Widgets

A WordPress plugin for showing LeagueApps data on your site. Each widget is available as both a **shortcode** and a **block**.

## Setup

1. Install and activate the plugin.
2. Go to **Settings → LeagueApps** and enter your LeagueApps **Site ID** and **public API key**. The key is sent in the `la-api-key` header to `https://public.leagueapps.io`.
3. API responses are cached as transients. The default is 15 minutes. You can change that and clear the cache on the same settings page.

## Widgets

### Standings

```
[leagueapps_standings sport="Soccer"]
[leagueapps_standings program_id="123456" ranking="points" show_scores="false"]
```

Block: **LeagueApps Standings** (Widgets category). Its sidebar has the same options.

| Attribute     | Default   | Notes |
|---------------|-----------|-------|
| `sport`       | —         | Matches the program's sport in LeagueApps (case-insensitive). Shows every current program in that sport. |
| `program_id`  | —         | Show one program instead. |
| `ranking`     | `win_pct` | `win_pct` or `points` (3 for a win, 1 for a tie). |
| `show_ties`   | `true`    | Shows the T column. |
| `show_scores` | `true`    | Shows the PF / PA / Diff columns. |
| `title`       | program name | Overrides the heading. |

**How standings are calculated:** the LeagueApps Public API doesn't document a standings endpoint. Standings are worked out from completed games in `/v1/sites/{siteId}/programs/{programId}/schedule`, and teams come from `/programs/{programId}/teams`. The code tries several common names for each response field. If your API responses use different names, adjust the mapping with the `leagueapps_widgets_game_fields` filter (see `includes/class-standings-calculator.php`).

## Adding a widget

1. Extend `LeagueApps_Widgets\Widget`. Implement `get_id()`, `get_title()`, `get_attributes()` (a schema) and `render( $atts )`.
2. Register it in `Plugin::__construct()`, or from another plugin:

```php
add_action( 'leagueapps_widgets_register', function ( $registry, $api ) {
    $registry->register( new My_Schedule_Widget( $api ) );
}, 10, 2 );
```

You get the shortcode `[leagueapps_{id}]` and the block `leagueapps-widgets/{id}` automatically. The block's sidebar controls are generated from the attribute schema, so no JavaScript is needed. Use `$api->site_get( 'path' )` for any `/v1/sites/{siteId}/…` endpoint.

## Hooks

- `leagueapps_widgets_register` (action): register widgets.
- `leagueapps_widgets_api_base_url`: override the API host.
- `leagueapps_widgets_game_fields`: response field names used for standings.
- `leagueapps_widgets_excluded_game_states`: game states that are ignored.
- `leagueapps_widgets_standings_programs`, `leagueapps_widgets_standings_rows`, `leagueapps_widgets_standings_columns`: change the standings output.

## Development

In Claude Code cloud sessions, the `.claude/settings.json` SessionStart hook runs `wp-start`. It starts a local WordPress at http://localhost:8080 (admin/admin) with this plugin linked and active.
