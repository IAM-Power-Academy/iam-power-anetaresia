<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aplikacioni IAM Power (PWA) — instalohet në telefon nga faqja.
 *
 *  /app/               faqja e aplikacionit: Biblioteka ime, Kurse, Skuadra
 *  /iamp-manifest.json manifesti i aplikacionit
 *  /iamp-sw.js         service worker (vetëm faqe offline — nuk ruan të dhëna personale)
 *  [iam_shkarko_app]   butoni “Shkarko aplikacionin” për çdo faqe
 *
 * Çmimet vijnë nga produktet WooCommerce të lidhura me kurset LearnDash.
 * Kurset që anëtari i ka shfaqen te Biblioteka pa pagesë; për të tjerat butoni çon te porosia.
 */
class IAMP_App {

	const RW_OPTION = 'iamp_app_rw';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_shortcode( 'iam_shkarko_app', array( __CLASS__, 'install_shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'floating_button' ) );
		add_action( 'wp_head', array( __CLASS__, 'head_links' ), 1 );
	}

	public static function app_url() {
		return home_url( '/app/' );
	}

	public static function rewrites() {
		add_rewrite_rule( '^app/?$', 'index.php?iamp_app=app', 'top' );
		add_rewrite_rule( '^iamp-manifest\.json$', 'index.php?iamp_app=manifest', 'top' );
		add_rewrite_rule( '^iamp-sw\.js$', 'index.php?iamp_app=sw', 'top' );
		if ( get_option( self::RW_OPTION ) !== IAMP_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::RW_OPTION, IAMP_VERSION, false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'iamp_app';
		return $vars;
	}

	public static function head_links() {
		if ( is_admin() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( home_url( '/iamp-manifest.json' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="#0b1a33">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( IAMP_URL . 'assets/app/apple-touch-icon.png' ) . '">' . "\n";
	}

	public static function serve() {
		$what = get_query_var( 'iamp_app' );
		if ( ! $what ) {
			return;
		}
		nocache_headers();
		if ( 'manifest' === $what ) {
			self::manifest();
		} elseif ( 'sw' === $what ) {
			self::service_worker();
		} else {
			self::page();
		}
		exit;
	}

	private static function manifest() {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		$icons = IAMP_URL . 'assets/app/';
		echo wp_json_encode(
			array(
				'name'             => 'IAM Power Academy',
				'short_name'       => 'IAM Power',
				'description'      => 'Kurset dhe biblioteka jote në IAM Power Academy.',
				'lang'             => 'sq',
				'start_url'        => '/app/',
				'scope'            => '/',
				'display'          => 'standalone',
				'orientation'      => 'portrait',
				'background_color' => '#0b1a33',
				'theme_color'      => '#0b1a33',
				'icons'            => array(
					array( 'src' => $icons . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
					array( 'src' => $icons . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
					array( 'src' => $icons . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
				),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	private static function service_worker() {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		echo <<<'JS'
// IAM Power — service worker minimal. Nuk ruan faqe personale; vetëm tregon mesazh kur s'ka internet.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function (e) {
  if (e.request.mode !== 'navigate') { return; }
  e.respondWith(fetch(e.request).catch(function () {
    return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b1a33;color:#f5efe2;font-family:sans-serif;text-align:center;padding:24px"><div><h2 style="color:#c9a14a">Nuk ka internet</h2><p>Lidhu me internetin dhe hap përsëri aplikacionin IAM Power.</p></div></body>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }));
});
JS;
	}

	/* ---------------------------------------------------------------
	 * Të dhënat
	 * ------------------------------------------------------------- */

	private static function hidden_ids( $uid ) {
		if ( class_exists( 'IAMP_Team_Front' ) && ! IAMP_Team_Front::can_see() ) {
			return IAMP_Team_Front::protected_ids();
		}
		return array();
	}

	/**
	 * Kurset që anëtari i ka (LearnDash + anëtarësitë aktive).
	 */
	private static function owned_course_ids( $uid ) {
		$ids = array();
		if ( function_exists( 'learndash_user_get_enrolled_courses' ) ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) learndash_user_get_enrolled_courses( $uid ) ) );
		}
		foreach ( (array) IAMP_DB::get_user_memberships( $uid, 'active' ) as $m ) {
			$ids = array_merge( $ids, array_map( 'intval', IAMP_DB::string_to_ids( $m->courses ) ) );
		}
		$ids = array_diff( array_unique( $ids ), array( 0 ), self::hidden_ids( $uid ) );
		return array_values( $ids );
	}

	private static function progress( $uid, $cid ) {
		if ( function_exists( 'learndash_course_progress' ) ) {
			$p = learndash_course_progress( array( 'user_id' => $uid, 'course_id' => $cid, 'array' => true ) );
			if ( is_array( $p ) && isset( $p['percentage'] ) ) {
				return max( 0, min( 100, (int) $p['percentage'] ) );
			}
		}
		return 0;
	}

	private static function library( $uid ) {
		$out = array();
		foreach ( self::owned_course_ids( $uid ) as $cid ) {
			$post = get_post( $cid );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$out[] = array(
				'id'       => $cid,
				'title'    => get_the_title( $cid ),
				'url'      => get_permalink( $cid ),
				'img'      => get_the_post_thumbnail_url( $cid, 'medium_large' ),
				'progress' => self::progress( $uid, $cid ),
			);
		}
		return $out;
	}

	/**
	 * Kurse për t'u blerë: produkte WooCommerce të lidhura me kurse LearnDash, që anëtari s'i ka.
	 * Filtri 'iamp_app_store_items' lejon shtimin e artikujve të tjerë (p.sh. link Ko-fi).
	 */
	private static function store( $owned ) {
		$items = array();
		if ( function_exists( 'wc_get_product' ) ) {
			$q = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 60,
					'no_found_rows'  => true,
					'meta_query'     => array( array( 'key' => '_related_course', 'compare' => 'EXISTS' ) ), // phpcs:ignore
				)
			);
			foreach ( $q->posts as $p ) {
				$product = wc_get_product( $p->ID );
				if ( ! $product || ! $product->is_visible() ) {
					continue;
				}
				$related = array_map( 'intval', (array) maybe_unserialize( get_post_meta( $p->ID, '_related_course', true ) ) );
				$related = array_filter( $related );
				if ( ! $related || ! array_diff( $related, $owned ) ) {
					continue; // të gjitha kurset e produktit i ka tashmë
				}
				$price = (float) $product->get_price();
				$items[] = array(
					'title' => $product->get_name(),
					'url'   => get_permalink( $p->ID ),
					'img'   => get_the_post_thumbnail_url( $p->ID, 'medium_large' ),
					'price' => '' === $product->get_price() || 0.0 === $price ? 'Falas' : html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' ),
					'free'  => ( '' === $product->get_price() || 0.0 === $price ),
				);
			}
		}
		return apply_filters( 'iamp_app_store_items', $items, $owned );
	}

	/**
	 * Kursi “Më i shituri” (shitet në Ko-fi). Filtri 'iamp_app_featured' lejon ndryshimin ose shtimin.
	 * Fshihet vetëm nëse anëtari ka tashmë një kurs me titull që përmban 'Zemra e Martes'.
	 */
	private static function featured( $uid ) {
		$items = apply_filters(
			'iamp_app_featured',
			array(
				array(
					'match' => 'Zemra e Martes',
					'title' => 'Zemra e Martesës',
					'img'   => IAMP_URL . 'assets/app/zemra-e-martes.jpg',
					'url'   => 'https://ko-fi.com/s/fad5cab7c2',
					'cta'   => 'Blej tani',
				),
			)
		);
		$out = array();
		foreach ( (array) $items as $it ) {
			$has = false;
			foreach ( self::owned_course_ids( $uid ) as $cid ) {
				if ( ! empty( $it['match'] ) && false !== stripos( remove_accents( get_the_title( $cid ) ), remove_accents( $it['match'] ) ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				$out[] = $it;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Faqja
	 * ------------------------------------------------------------- */

	private static function page() {
		$logged = is_user_logged_in();
		$uid    = get_current_user_id();
		$user   = wp_get_current_user();
		$login  = add_query_arg( 'redirect_to', rawurlencode( self::app_url() ), IAMP_Settings::login_url() );
		$lib    = $logged ? self::library( $uid ) : array();
		$owned  = wp_list_pluck( $lib, 'id' );
		$store  = $logged ? self::store( $owned ) : array();
		$feat   = $logged ? self::featured( $uid ) : array();
		$team   = $logged && class_exists( 'IAMP_Team_Front' ) && IAMP_Team_Front::can_see();
		$tcid   = $team ? (int) get_option( 'iamp_team_course' ) : 0;
		$icons  = IAMP_URL . 'assets/app/';
		$first  = $logged ? ( $user->first_name ? $user->first_name : $user->display_name ) : '';

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="sq">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>IAM Power Academy</title>
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0b1a33">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="IAM Power">
<link rel="manifest" href="<?php echo esc_url( home_url( '/iamp-manifest.json' ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( $icons . 'apple-touch-icon.png' ); ?>">
<link rel="icon" href="<?php echo esc_url( $icons . 'icon-192.png' ); ?>">
<style>
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;background:#0b1a33;color:#f5efe2;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
body{padding-bottom:calc(76px + env(safe-area-inset-bottom));min-height:100vh}
a{color:inherit;text-decoration:none}
.top{padding:calc(18px + env(safe-area-inset-top)) 18px 14px;background:linear-gradient(180deg,#13264a,#0b1a33);display:flex;align-items:center;gap:12px}
.top img{width:44px;height:44px;border-radius:12px;border:1px solid #c9a14a}
.top h1{margin:0;font-size:19px;line-height:1.2;color:#c9a14a;font-weight:700}
.top p{margin:2px 0 0;font-size:13px;color:#bfb293}
.install{margin:0 18px 6px;display:none;width:calc(100% - 36px);padding:13px;border:1px solid #c9a14a;border-radius:12px;background:rgba(201,161,74,.12);color:#c9a14a;font-weight:700;font-size:15px;cursor:pointer}
.tab{display:none;padding:8px 18px 18px}.tab.on{display:block}
h2{font-size:17px;margin:20px 0 12px;color:#e6d7b5}
.grid{display:grid;gap:14px}
.card{display:block;background:#13264a;border:1px solid rgba(201,161,74,.35);border-radius:16px;overflow:hidden}
.card .im{aspect-ratio:16/9;background:#0b1a33 center/cover no-repeat;border-bottom:1px solid rgba(201,161,74,.25)}
.card .im.no{display:flex;align-items:center;justify-content:center;color:#c9a14a;font-weight:700;font-size:26px}
.card .bd{padding:14px}
.card h3{margin:0 0 10px;font-size:16px;line-height:1.35;color:#fff}
.bar{height:7px;background:#0b1a33;border-radius:99px;overflow:hidden}
.bar i{display:block;height:100%;background:#c9a14a;border-radius:99px}
.meta{display:flex;justify-content:space-between;align-items:center;margin-top:9px;font-size:13px;color:#bfb293}
.btn{display:inline-block;padding:10px 16px;border-radius:10px;background:#c9a14a;color:#0b1a33;font-weight:700;font-size:14px}
.price{font-size:18px;font-weight:800;color:#c9a14a}
.price.free{color:#7be0a0}
.row{display:flex;justify-content:space-between;align-items:center;gap:10px}
.feat{display:block;border-radius:18px;overflow:hidden;border:1px solid #c9a14a;box-shadow:0 10px 30px rgba(0,0,0,.45);margin-bottom:16px;background:#13264a}
.feat img{display:block;width:100%;height:auto}
.feat .fb{padding:14px;display:flex;gap:10px;align-items:center;justify-content:space-between}
.feat .fb span{font-size:13px;color:#bfb293}
.feat .btn{padding:13px 22px;font-size:16px}
.empty,.hello{background:#13264a;border:1px dashed rgba(201,161,74,.5);border-radius:16px;padding:22px;text-align:center;color:#e6d7b5}
.hello h2{margin:0 0 8px;color:#c9a14a;font-size:20px}
.nav{position:fixed;left:0;right:0;bottom:0;display:flex;background:#0a162c;border-top:1px solid rgba(201,161,74,.4);padding-bottom:env(safe-area-inset-bottom);z-index:5}
.nav button{flex:1;background:none;border:0;color:#8f8467;padding:11px 4px 9px;font-size:12px;cursor:pointer}
.nav button b{display:block;font-size:20px;margin-bottom:2px}
.nav button.on{color:#c9a14a}
.sheet{position:fixed;inset:0;background:rgba(0,0,0,.65);display:none;align-items:flex-end;z-index:20}
.sheet.on{display:flex}
.sheet div{background:#13264a;border-top:2px solid #c9a14a;border-radius:20px 20px 0 0;padding:22px 20px calc(24px + env(safe-area-inset-bottom));width:100%}
.sheet h3{margin:0 0 10px;color:#c9a14a}
.sheet ol{padding-left:20px;line-height:1.7;margin:0 0 14px}
.sheet button{width:100%;padding:13px;border:0;border-radius:10px;background:#c9a14a;color:#0b1a33;font-weight:700;font-size:15px}
.login{padding:40px 22px;text-align:center}
.login img{width:96px;height:96px;border-radius:24px;border:1px solid #c9a14a}
.login h1{color:#c9a14a;margin:18px 0 8px}
.login p{color:#bfb293;margin:0 0 24px}
.login .btn{padding:14px 28px;font-size:16px}
</style>
</head>
<body>
<?php if ( ! $logged ) : ?>
	<div class="login">
		<img src="<?php echo esc_url( $icons . 'icon-192.png' ); ?>" alt="IAM Power">
		<h1>IAM Power Academy</h1>
		<p>Kyçu për të hapur bibliotekën me kurset e tua.</p>
		<a class="btn" href="<?php echo esc_url( $login ); ?>">Hyr</a>
		<button class="install" id="inst" type="button" style="margin-top:26px">📲 Shkarko aplikacionin</button>
	</div>
<?php else : ?>
	<div class="top">
		<img src="<?php echo esc_url( $icons . 'icon-192.png' ); ?>" alt="">
		<div><h1>Përshëndetje, <?php echo esc_html( $first ); ?></h1><p>IAM Power Academy</p></div>
	</div>
	<button class="install" id="inst" type="button">📲 Shkarko aplikacionin</button>

	<section class="tab on" id="t-lib">
		<h2>Biblioteka ime</h2>
		<?php if ( ! $lib ) : ?>
			<div class="empty">Biblioteka jote është ende bosh.<br>Shiko kurset te skeda <b>Kurse</b>.</div>
		<?php else : ?>
			<div class="grid">
			<?php foreach ( $lib as $c ) : ?>
				<a class="card" href="<?php echo esc_url( $c['url'] ); ?>">
					<?php if ( $c['img'] ) : ?><div class="im" style="background-image:url('<?php echo esc_url( $c['img'] ); ?>')"></div><?php else : ?><div class="im no">IAM</div><?php endif; ?>
					<div class="bd">
						<h3><?php echo esc_html( $c['title'] ); ?></h3>
						<div class="bar"><i style="width:<?php echo (int) $c['progress']; ?>%"></i></div>
						<div class="meta"><span><?php echo (int) $c['progress']; ?>% e përfunduar</span><span class="btn"><?php echo $c['progress'] > 0 ? 'Vazhdo' : 'Fillo'; ?></span></div>
					</div>
				</a>
			<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<section class="tab" id="t-store">
		<?php foreach ( $feat as $f ) : ?>
			<h2>🔥 Më i shituri</h2>
			<a class="feat" href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener">
				<img src="<?php echo esc_url( $f['img'] ); ?>" alt="<?php echo esc_attr( $f['title'] ); ?>" loading="lazy">
				<div class="fb"><span>Kurs 12-mujor</span><span class="btn"><?php echo esc_html( $f['cta'] ); ?></span></div>
			</a>
		<?php endforeach; ?>
		<h2>Kurse të tjera</h2>
		<?php if ( ! $store ) : ?>
			<div class="empty"><?php echo $feat ? 'Nuk ka kurse të tjera për momentin.' : 'Ke tashmë të gjitha kurset e disponueshme. 🌟'; ?></div>
		<?php else : ?>
			<div class="grid">
			<?php foreach ( $store as $s ) : ?>
				<a class="card" href="<?php echo esc_url( $s['url'] ); ?>">
					<?php if ( ! empty( $s['img'] ) ) : ?><div class="im" style="background-image:url('<?php echo esc_url( $s['img'] ); ?>')"></div><?php else : ?><div class="im no">IAM</div><?php endif; ?>
					<div class="bd">
						<h3><?php echo esc_html( $s['title'] ); ?></h3>
						<div class="row"><span class="price<?php echo ! empty( $s['free'] ) ? ' free' : ''; ?>"><?php echo esc_html( $s['price'] ); ?></span><span class="btn"><?php echo ! empty( $s['free'] ) ? 'Fillo falas' : 'Shiko dhe bli'; ?></span></div>
					</div>
				</a>
			<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $team ) : ?>
	<section class="tab" id="t-team">
		<h2>Skuadra 2026</h2>
		<div class="grid">
			<?php if ( $tcid ) : ?><a class="card" href="<?php echo esc_url( get_permalink( $tcid ) ); ?>"><div class="bd"><h3>Regjistro shitjen dhe Paneli im</h3><div class="row"><span class="meta">Kursi privat i Skuadrës</span><span class="btn">Hap</span></div></div></a><?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<nav class="nav">
		<button type="button" data-t="t-lib" class="on"><b>📚</b>Biblioteka</button>
		<button type="button" data-t="t-store"><b>🛍️</b>Kurse</button>
		<?php if ( $team && $tcid ) : ?><button type="button" data-t="t-team"><b>⭐</b>Skuadra</button><?php endif; ?>
		<a style="flex:1;text-align:center;color:#8f8467;padding:11px 4px 9px;font-size:12px" href="<?php echo esc_url( home_url( '/' ) ); ?>"><b style="display:block;font-size:20px;margin-bottom:2px">🌐</b>Faqja</a>
	</nav>
<?php endif; ?>

<div class="sheet" id="sheet"><div>
	<h3>Shto IAM Power në telefon</h3>
	<ol id="steps"></ol>
	<button type="button" id="sheet-x">E kuptova</button>
</div></div>

<script>
(function () {
  var inst = document.getElementById('inst'), sheet = document.getElementById('sheet'), steps = document.getElementById('steps'), dp = null;
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
  if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/iamp-sw.js', { scope: '/' }).catch(function () {}); }
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); dp = e; if (inst && !standalone) { inst.style.display = 'block'; } });
  if (inst && !standalone && ios) { inst.style.display = 'block'; }
  function help() {
    steps.innerHTML = ios
      ? '<li>Hape këtë faqe në <b>Safari</b>.</li><li>Shtyp butonin <b>Share</b> (katrori me shigjetë lart).</li><li>Zgjidh <b>“Add to Home Screen”</b> / “Shto në Ekranin Kryesor”.</li><li>Shtyp <b>Add</b>.</li>'
      : '<li>Hap menynë e shfletuesit (⋮ lart djathtas).</li><li>Zgjidh <b>“Install app”</b> / “Shto në ekranin kryesor”.</li><li>Konfirmo.</li>';
    sheet.className = 'sheet on';
  }
  if (inst) { inst.addEventListener('click', function () { if (dp) { dp.prompt(); dp.userChoice.then(function () { dp = null; inst.style.display = 'none'; }); } else { help(); } }); }
  document.getElementById('sheet-x').addEventListener('click', function () { sheet.className = 'sheet'; });
  if (/[?&]instalo=1/.test(location.search) && !standalone) { help(); }
  var tabs = document.querySelectorAll('.tab'), btns = document.querySelectorAll('.nav button');
  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener('click', function () {
      var t = this.getAttribute('data-t');
      for (var a = 0; a < tabs.length; a++) { tabs[a].className = 'tab' + (tabs[a].id === t ? ' on' : ''); }
      for (var b = 0; b < btns.length; b++) { btns[b].className = btns[b] === this ? 'on' : ''; }
      window.scrollTo(0, 0);
    });
  }
})();
</script>
</body>
</html>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Butoni “Shkarko aplikacionin” në faqen e zakonshme
	 * ------------------------------------------------------------- */

	private static function install_js() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<script>(function(){var dp=null;window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();dp=e;});'
			. 'document.addEventListener("click",function(ev){var b=ev.target.closest&&ev.target.closest("[data-iamp-install]");if(!b){return;}'
			. 'if(window.matchMedia("(display-mode: standalone)").matches){return;}'
			. 'if(dp){ev.preventDefault();dp.prompt();dp.userChoice.then(function(){dp=null;});}});})();</script>';
	}

	public static function install_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'tekst' => '📲 Shkarko aplikacionin IAM Power' ), $atts );
		$url  = add_query_arg( 'instalo', '1', self::app_url() );
		return '<p style="text-align:center"><a data-iamp-install href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 26px;border-radius:10px;background:#c9a14a;color:#0b1a33;font-weight:700;text-decoration:none;font-size:16px">' . esc_html( $atts['tekst'] ) . '</a></p>' . self::install_js();
	}

	/**
	 * Buton lundrues për anëtarët e kyçur, te faqja e zakonshme. Mbyllet dhe nuk shfaqet më.
	 */
	public static function floating_button() {
		if ( is_admin() || ! is_user_logged_in() || get_query_var( 'iamp_app' ) ) {
			return;
		}
		if ( ! apply_filters( 'iamp_app_floating_button', true ) ) {
			return;
		}
		$url = add_query_arg( 'instalo', '1', self::app_url() );
		?>
<div id="iamp-fab" style="display:none;position:fixed;right:14px;bottom:14px;z-index:99999;background:#0b1a33;border:1px solid #c9a14a;border-radius:14px;padding:10px 12px;box-shadow:0 6px 24px rgba(0,0,0,.35);font-family:inherit;max-width:260px">
	<a data-iamp-install href="<?php echo esc_url( $url ); ?>" style="display:block;color:#c9a14a;font-weight:700;text-decoration:none;font-size:14px">📲 Shkarko aplikacionin IAM Power</a>
	<button type="button" id="iamp-fab-x" aria-label="Mbyll" style="position:absolute;top:-9px;right:-9px;width:24px;height:24px;border-radius:50%;border:1px solid #c9a14a;background:#13264a;color:#c9a14a;cursor:pointer;line-height:1;padding:0">×</button>
</div>
<script>(function(){var f=document.getElementById('iamp-fab');if(!f){return;}
if(window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone){return;}
if(!/android|iphone|ipad|ipod/i.test(navigator.userAgent)){return;}
try{if(localStorage.getItem('iamp_fab_x')){return;}}catch(e){}
f.style.display='block';
document.getElementById('iamp-fab-x').addEventListener('click',function(){f.style.display='none';try{localStorage.setItem('iamp_fab_x','1');}catch(e){}});})();</script>
		<?php
		echo self::install_js(); // phpcs:ignore
	}
}
