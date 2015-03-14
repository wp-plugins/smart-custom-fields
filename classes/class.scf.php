<?php
/**
 * SCF
 * Version    : 1.1.2
 * Author     : Takashi Kitajima
 * Created    : September 23, 2014
 * Modified   : March 13, 2015
 * License    : GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */
class SCF {

	/**
	 * Smart Custom Fields に登録されているフォームフィールド（field）のインスタンスの配列
	 * @var array
	 */
	protected static $fields = array();

	/**
	 * データ取得処理は重いので、一度取得したデータは cache に保存する。
	 * キーに post_id を設定すること。
	 * @var array
	 */
	protected static $cache = array();

	/**
	 * データ取得処理は重いので、一度取得した設定データは settings_posts_cache に保存する。
	 * キーに post_type を設定すること。
	 * @var array
	 */
	protected static $settings_posts_cache = array();

	/**
	 * データ取得処理は重いので、一度取得した設定データは cache に保存する。
	 * キーに post_type を設定すること。
	 * @var array
	 */
	protected static $settings_cache = array();

	/**
	 * データ取得処理は重いので、一度取得した設定データは cache に保存する。
	 * キーに post_id を設定すること。
	 * @var array
	 */
	protected static $repeat_multiple_data_cache = array();

	/**
	 * その投稿の全てのメタデータを良い感じに取得
	 * 
	 * @param int $post_id
	 * @return array
	 */
	public static function gets( $post_id = null ) {
		if ( is_null( $post_id ) ) {
			$post_id = get_the_ID();
		}
		$post_id = self::get_real_post_id( $post_id );

		if ( empty( $post_id ) ) {
			return array();
		}

		// 設定画面で未設定のメタデータは投稿が保持していても出力しないようにしないといけないので
		// 設定データを取得して出力して良いか判別する
		$post_type = self::get_public_post_type( $post_id );
		$settings  = self::get_settings( $post_type, $post_id );
		$post_meta = array();
		foreach ( $settings as $Setting ) {
			$groups = $Setting->get_groups();
			foreach ( $groups as $Group ) {
				$is_repeatable = $Group->is_repeatable();
				$group_name    = $Group->get_name();
				if ( $is_repeatable && $group_name ) {
					$post_meta[$group_name] = self::get_values_by_group( $post_id, $Group );
				}
				else {
					$fields = $Group->get_fields();
					foreach ( $fields as $Field ) {
						$field_name = $Field->get( 'name' );
						$post_meta[$field_name] = self::get_value_by_field( $post_id, $Field, $is_repeatable );
					}
				}
			}
		}
		return $post_meta;
	}

	/**
	 * その投稿の任意のメタデータを良い感じに取得
	 * 
	 * @param string $name グループ名もしくはフィールド名
	 * @param int $post_id
	 * @return mixed
	 */
	public static function get( $name, $post_id = null ) {
		if ( is_null( $post_id ) ) {
			$post_id = get_the_ID();
		}
		$post_id = self::get_real_post_id( $post_id );

		if ( empty( $post_id ) ) {
			return;
		}

		if ( self::get_cache( $post_id, $name ) ) {
			self::debug_cache_message( "use get cache. {$post_id} {$name}" );
			return self::get_cache( $post_id, $name );
		} else {
			self::debug_cache_message( "dont use get cache... {$post_id} {$name}" );
		}

		// 設定画面で未設定のメタデータは投稿が保持していても出力しないようにしないといけないので
		// 設定データを取得して出力して良いか判別する
		$post_type = self::get_public_post_type( $post_id );
		$settings  = self::get_settings( $post_type, $post_id );
		foreach ( $settings as $Setting ) {
			$groups = $Setting->get_groups();
			foreach ( $groups as $Group ) {
				// グループ名と一致する場合はそのグループ内のフィールドを配列で返す
				$is_repeatable = $Group->is_repeatable();
				$group_name    = $Group->get_name();
				if ( $is_repeatable && $group_name && $group_name === $name ) {
					return self::get_values_by_group( $post_id, $Group );
				}
				// グループ名と一致しない場合は一致するフィールドを返す
				else {
					$fields = $Group->get_fields();
					foreach ( $fields as $Field ) {
						$field_name = $Field->get( 'name' );
						if ( $field_name === $name ) {
							return self::get_value_by_field( $post_id, $Field, $is_repeatable );
						}
					}
				}
			}
		}
	}

	/**
	 * Post ID がリビジョンのものでも良い感じに投稿タイプを取得
	 * 
	 * @param int $post_id
	 * @return string
	 */
	protected static function get_public_post_type( $post_id ) {
		if ( $public_post_id = wp_is_post_revision( $post_id ) ) {
			$post_type = get_post_type( $public_post_id );
		} else {
			$post_type = get_post_type( $post_id );
		}
		return $post_type;
	}

	/**
	 * プレビューのときはそのプレビューの Post ID を返す
	 *
	 * @param int $post_id
	 * @return int
	 */
	protected static function get_real_post_id( $post_id ) {
		if ( is_preview() ) {
			$preview_post = wp_get_post_autosave( $post_id );
			if ( isset( $preview_post->ID ) ) {
				$post_id = $preview_post->ID;
			}
		}
		return $post_id;
	}

	/**
	 * キャシュに保存
	 * 
	 * @param int $post_id
	 * @param string $name
	 * @param mixed $data
	 */
	protected static function save_cache( $post_id, $name, $data ) {
		self::$cache[$post_id][$name] = $data;
	}

	/**
	 * キャッシュを取得
	 * 
	 * @param int $post_id
	 * @param string $name
	 * @return mixed
	 */
	protected static function get_cache( $post_id, $name = null ) {
		if ( is_null( $name ) ) {
			if ( isset( self::$cache[$post_id] ) ) {
				return self::$cache[$post_id];
			}
		} else {
			if ( isset( self::$cache[$post_id][$name] ) ) {
				return self::$cache[$post_id][$name];
			}
		}
	}

	/**
	 * そのグループのメタデータを取得
	 * 
	 * @param int $post_id
	 * @param Smart_Custom_Fields_Group $Group
	 * @return mixed
	 */
	protected static function get_values_by_group( $post_id, $Group ) {
		$post_meta = array();
		$fields    = $Group->get_fields();
		foreach ( $fields as $Field ) {
			$field_name = $Field->get( 'name' );
			if ( !$field_name ) {
				continue;
			}
			$_post_meta = get_post_meta( $post_id, $field_name );
			// チェックボックスの場合
			$repeat_multiple_data = self::get_repeat_multiple_data( $post_id );
			if ( is_array( $repeat_multiple_data ) && array_key_exists( $field_name, $repeat_multiple_data ) ) {
				$start = 0;
				foreach ( $repeat_multiple_data[$field_name] as $repeat_multiple_key => $repeat_multiple_value ) {
					if ( $repeat_multiple_value === 0 ) {
						$value = array();
					} else {
						$value  = array_slice( $_post_meta, $start, $repeat_multiple_value );
						$start += $repeat_multiple_value;
					}
					$post_meta[$repeat_multiple_key][$field_name] = $value;
				}
			}
			// チェックボックス以外
			else {
				$field_type = $Field->get_attribute( 'type' );
				foreach ( $_post_meta as $_post_meta_key => $value ) {
					// wysiwyg の場合はフィルタを通す
					if ( $field_type === 'wysiwyg' ) {
						$value = self::add_the_content_filter( $value );
					}
					// relation のときは $value = Post ID。公開済みでない場合は取得しない
					elseif ( $field_type === 'relation' ) {
						if ( get_post_status( $value ) !== 'publish' ) {
							continue;
						}
					}
					$post_meta[$_post_meta_key][$field_name] = $value;
				}
			}
		}
		self::save_cache( $post_id, $Group->get_name(), $post_meta );
		return $post_meta;
	}

	/**
	 * そのフィールドのメタデータを取得
	 * 
	 * @param int $post_id
	 * @param array $field
	 * @param bool $is_repeatable このフィールドが所属するグループが repeat かどうか
	 * @return mixed $post_meta
	 */
	protected static function get_value_by_field( $post_id, $Field, $is_repeatable ) {
		$field_name = $Field->get( 'name' );
		if ( !$field_name ) {
			return;
		}
		if ( $Field->get_attribute( 'allow-multiple-data' ) || $is_repeatable ) {
			$post_meta = get_post_meta( $post_id, $field_name );
		} else {
			$post_meta = get_post_meta( $post_id, $field_name, true );
		}
		
		$field_type = $Field->get_attribute( 'type' );
		if ( in_array( $field_type, array( 'wysiwyg' ) ) ) {
			if ( is_array( $post_meta ) ) {
				$_post_meta = array();
				foreach ( $post_meta as $key => $value ) {
					$_post_meta[$key] = self::add_the_content_filter( $value );
				}
				$post_meta = $_post_meta;
			} else {
				$post_meta = self::add_the_content_filter( $post_meta );
			}
		} elseif ( $field_type === 'relation' ) {
			$_post_meta = array();
			if ( is_array( $post_meta ) ) {
				foreach ( $post_meta as $post_id ) {
					if ( get_post_status( $post_id ) !== 'publish' ) {
						continue;
					}
					$_post_meta[] = $post_id;
				}
			}
			$post_meta = $_post_meta;
		}
		self::save_cache( $post_id, $field_name, $post_meta );
		return $post_meta;
	}

	/**
	 * その投稿タイプで有効になっている SCF をキャッシュに保存
	 *
	 * @param string $post_type
	 * @param array $settings_posts
	 */
	protected static function save_settings_posts_cache( $post_type, $settings_posts ) {
		self::$settings_posts_cache[$post_type] = $settings_posts;
	}

	/**
	 * その投稿タイプで有効になっている SCF のキャッシュを取得
	 *
	 * @param string $post_type
	 * @return array
	 */
	public static function get_settings_posts_cache( $post_type ) {
		if ( isset( self::$settings_posts_cache[$post_type] ) ) {
			return self::$settings_posts_cache[$post_type];
		}
		return array();
	}

	/**
	 * その投稿タイプで有効になっている SCF を取得
	 * 
	 * @param string $post_type
	 * @return array $settings
	 */
	public static function get_settings_posts( $post_type ) {
		$posts = array();
		if ( self::get_settings_posts_cache( $post_type ) ) {
			self::debug_cache_message( "use settings posts cache. {$post_type}" );
			return self::get_settings_posts_cache( $post_type );
		} else {
			self::debug_cache_message( "dont use settings posts cache... {$post_type}" );
		}
		$settings_posts = get_posts( array(
			'post_type'      => SCF_Config::NAME,
			'posts_per_page' => -1,
			'order'          => 'ASC',
			'order_by'       => 'menu_order',
			'meta_query'     => array(
				array(
					'key'     => SCF_Config::PREFIX . 'condition',
					'compare' => 'LIKE',
					'value'   => $post_type,
				),
			),
		) );
		self::save_settings_posts_cache( $post_type, $settings_posts );
		return $settings_posts;
	}

	/**
	 * Setting オブジェクトをキャッシュに保存
	 *
	 * @param string $post_type
	 * @param int|false $post_id
	 * @param Smart_Custom_Fields_Setting $Setting
	 */
	protected static function save_settings_cache( $post_type, $post_id, $Setting ) {
		if ( empty( $post_id ) ) {
			self::$settings_cache[$post_type][0][] = $Setting;
		} else {
			self::$settings_cache[$post_type][$post_id][] = $Setting;
		}
	}

	/**
	 * Setting オブジェクトキャッシュを取得
	 *
	 * @param string $post_type
	 * @param int|false $post_id
	 * @return array
	 */
	public static function get_settings_cache( $post_type, $post_id ) {
		$settings = array();
		if ( empty( $post_id ) ) {
			return $settings;
		}
		if ( !isset( self::$settings_cache[$post_type] ) ) {
			return $settings;
		}
		if ( isset( self::$settings_cache[$post_type][0] ) ) {
			$settings = self::$settings_cache[$post_type][0];
		}
		if ( !empty( $post_id ) ) {
			if ( isset( self::$settings_cache[$post_type][$post_id] ) ) {
				$settings = array_merge( $settings, self::$settings_cache[$post_type][$post_id] );
			}
		}
		return $settings;
	}

	/**
	 * Setting オブジェクトの配列を取得
	 *
	 * @param string $post_type
	 * @param int|false $post_id
	 * @return array $settings
	 */
	public static function get_settings( $post_type, $post_id ) {
		if ( empty( $post_id ) ) {
			$post_id = get_the_ID();
		}
		if ( self::get_settings_cache( $post_type, $post_id ) ) {
			self::debug_cache_message( "use settings cache. {$post_type} {$post_id}" );
			return self::get_settings_cache( $post_type, $post_id );
		} else {
			self::debug_cache_message( "dont use settings cache... {$post_type} {$post_id}" );
		}
		$settings = array();
		$settings_posts = self::get_settings_posts( $post_type );
		foreach ( $settings_posts as $settings_post ) {
			$condition_post_ids_raw = get_post_meta(
				$settings_post->ID,
				SCF_Config::PREFIX . 'condition-post-ids',
				true
			);
			if ( $condition_post_ids_raw ) {
				$condition_post_ids_raw = explode( ',', $condition_post_ids_raw );
				foreach ( $condition_post_ids_raw as $condition_post_id ) {
					$condition_post_id = trim( $condition_post_id );
					$Setting = SCF::add_setting( $settings_post->ID, $settings_post->post_title );
					if ( $post_id == $condition_post_id ) {
						$settings[] = $Setting;
					}
					self::save_settings_cache( $post_type, $condition_post_id, $Setting );
				}
			} else {
				$Setting = SCF::add_setting( $settings_post->ID, $settings_post->post_title );
				$settings[] = $Setting;
				self::save_settings_cache( $post_type, false, $Setting );
			}
		}
		$settings = apply_filters( SCF_Config::PREFIX . 'register-fields', $settings, $post_type, $post_id );
		return $settings;
	}

	/**
	 * 繰り返しに設定された複数許可フィールドデータの区切り識別用データをキャッシュに保存
	 *
	 * @param int $post_id
	 * @param mixed $repeat_multiple_data
	 */
	protected static function save_repeat_multiple_data_cache( $post_id, $repeat_multiple_data ) {
		self::$repeat_multiple_data_cache[$post_id] = $repeat_multiple_data;
	}

	/**
	 * 繰り返しに設定された複数許可フィールドデータの区切り識別用データを取得
	 * 
	 * @param int $post_id
	 * @return mixed
	 */
	public static function get_repeat_multiple_data( $post_id ) {
		$repeat_multiple_data = array();
		if ( isset( self::$repeat_multiple_data_cache[$post_id] ) ) {
			return self::$repeat_multiple_data_cache[$post_id];
		}
		if ( empty( $repeat_multiple_data ) ) {
			$_repeat_multiple_data = get_post_meta( $post_id, SCF_Config::PREFIX . 'repeat-multiple-data', true );
			if ( $_repeat_multiple_data ) {
				$repeat_multiple_data = $_repeat_multiple_data;
			}
		}
		self::save_repeat_multiple_data_cache( $post_id, $repeat_multiple_data );
		return $repeat_multiple_data;
	}

	/**
	 * null もしくは空値の場合は true
	 * 
	 * @param mixed $value
	 * @return bool
	 */
	public static function is_empty( &$value ) {
		if ( isset( $value ) ) {
			if ( is_null( $value ) || $value === '' ) {
				return true;
			}
			return false;
		}
		return true;
	}

	/**
	 * 使用可能なフォームフィールドオブジェクトを追加
	 * 
	 * @param Smart_Custom_Fields_Field_Base $instance
	 */
	public static function add_form_field_instance( Smart_Custom_Fields_Field_Base $instance ) {
		$type = $instance->get_attribute( 'type' );
		if ( !empty( $type ) ) {
			self::$fields[$type] = $instance;
		}
	}

	/**
	 * 使用可能なフォームフィールドオブジェクトを取得
	 * 
	 * @param string $type フォームフィールドの type
	 * @param Smart_Custom_Fields_Field_Base
	 */
	public static function get_form_field_instance( $type ) {
		if ( !empty( self::$fields[$type] ) ) {
			return clone self::$fields[$type];
		}
	}

	/**
	 * 全ての使用可能なフォームフィールドオブジェクトを取得
	 *
	 * @return array
	 */
	public static function get_form_field_instances() {
		$fields = array();
		foreach ( self::$fields as $type => $instance ) {
			$fields[$type] = self::get_form_field_instance( $type );
		}
		return $fields;
	}

	/**
	 * 管理画面で保存されたフィールドを取得
	 * 同じ投稿タイプで、同名のフィールド名を持つフィールドを複数定義しても一つしか返らないので注意
	 * 
	 * @param string $post_type
	 * @param string $field_name
	 * @return Smart_Custom_Fields_Field_Base
	 */
	public static function get_field( $post_type, $field_name ) {
		$settings = self::get_settings( $post_type, get_the_ID() );
		foreach ( $settings as $Setting ) {
			$groups = $Setting->get_groups();
			foreach ( $groups as $Group ) {
				$fields = $Group->get_fields();
				foreach ( $fields as $Field ) {
					if ( !is_null( $Field ) && $Field->get( 'name' ) === $field_name ) {
						return $Field;
					}
				}
			}
		}
	}
	
	/**
	 * 改行区切りの $choices を配列に変換
	 * 
	 * @param string $choices
	 * @return array
	 */
	public static function choices_eol_to_array( $choices ) {
		if ( !is_array( $choices ) ) {
			$choices = str_replace( array( "\r\n", "\r", "\n" ), "\n", $choices );
			return explode( "\n", $choices );
		}
		return $choices;
	}

	/**
	 * Setting を生成して返す
	 *
	 * @param string $id
	 * @param string $title
	 */
	public static function add_setting( $id, $title ) {
		return new Smart_Custom_Fields_Setting( $id, $title );
	}

	/**
	 * デフォルトで the_content に適用される関数を適用
	 *
	 * @param string $value
	 * @return string
	 */
	protected static function add_the_content_filter( $value ) {
		if ( has_filter( 'the_content', 'wptexturize' ) ) {
			$value = wptexturize( $value );
		}
		if ( has_filter( 'the_content', 'convert_smilies' ) ) {
			$value = convert_smilies( $value );
		}
		if ( has_filter( 'the_content', 'convert_chars' ) ) {
			$value = convert_chars( $value );
		}
		if ( has_filter( 'the_content', 'wpautop' ) ) {
			$value = wpautop( $value );
		}
		if ( has_filter( 'the_content', 'shortcode_unautop' ) ) {
			$value = shortcode_unautop( $value );
		}
		if ( has_filter( 'the_content', 'prepend_attachment' ) ) {
			$value = prepend_attachment( $value );
		}
		return $value;
	}

	/**
	 * キャッシュの使用状況を画面に表示
	 */
	protected static function debug_cache_message( $message ) {
		if ( defined( 'SCF_DEBUG_CACHE' ) && SCF_DEBUG_CACHE === true ) {
			echo $message . '<br />';
		}
	}
}
