<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Reads announcements from GET /v1/sites/{siteId}/announcements.
 *
 * The LeagueApps API documentation gives the endpoint (a reverse
 * chronological list) but no sample response, so field names start with
 * the ones its blog endpoint uses (title, summary, urlHtml, createdOn,
 * imageUrl) and fall back to other common names. Adjust them with the
 * `leagueapps_widgets_announcement_fields` filter.
 */
class Announcement_Data {

	public static function field_map() {
		return apply_filters(
			'leagueapps_widgets_announcement_fields',
			array(
				'list'    => array( 'announcements', 'items' ),
				'id'      => array( 'announcementId', 'id' ),
				'title'   => array( 'title', 'subject', 'name', 'headline' ),
				'body'    => array( 'message', 'body', 'content', 'text', 'description', 'summary' ),
				'summary' => array( 'summary' ),
				'date'    => array( 'createdOn', 'postedOn', 'publishDate', 'publishedOn', 'startDate', 'startTime', 'date', 'lastUpdated' ),
				'expires' => array( 'endDate', 'expirationDate', 'expiresOn', 'expireDate', 'endTime' ),
				'url'     => array( 'urlHtml', 'url', 'link', 'announcementUrlHtml' ),
				'image'   => array( 'imageUrl', 'halfImageUrl', 'image' ),
			)
		);
	}

	/**
	 * @return array[] In API order (newest first). Each: id, title, body (HTML,
	 *                 unsanitized), summary, date and expires (Unix timestamps or
	 *                 null), url, image (absolute URLs or '').
	 */
	public static function announcements( $response ) {
		$map = self::field_map();
		$out = array();
		foreach ( Schedule_Data::list_from( $response, $map['list'] ) as $raw ) {
			if ( ! is_array( $raw ) || ! empty( $raw['deleted'] ) ) {
				continue;
			}
			$item = array(
				'id'      => Schedule_Data::pick( $raw, $map['id'] ),
				'title'   => trim( (string) Schedule_Data::pick( $raw, $map['title'] ) ),
				'body'    => trim( (string) Schedule_Data::pick( $raw, $map['body'] ) ),
				'summary' => trim( (string) Schedule_Data::pick( $raw, $map['summary'] ) ),
				'date'    => Schedule_Data::parse_time( Schedule_Data::pick( $raw, $map['date'] ) ),
				'expires' => Schedule_Data::parse_time( Schedule_Data::pick( $raw, $map['expires'] ) ),
				'url'     => self::absolute_url( Schedule_Data::pick( $raw, $map['url'] ) ),
				'image'   => self::absolute_url( Schedule_Data::pick( $raw, $map['image'] ) ),
				'raw'     => $raw,
			);
			if ( '' !== $item['title'] || '' !== $item['body'] ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/** LeagueApps links are often protocol-relative ("//club.leagueapps.com/..."). */
	public static function absolute_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ? esc_url_raw( $url ) : '';
	}
}
