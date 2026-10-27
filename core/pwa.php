<?php
/**
 * PWA 머리 태그 — 모든 모듈의 <head> 에 한 줄로 넣는다.
 *
 *     echo pwa_head_tags('../');
 *
 * 인자는 **이 페이지에서 포털 루트까지 되짚는 접두사**다. 모듈마다 자기 $base 를
 * 이미 들고 있지만 기준이 제각각이라(모듈 루트 기준인 곳이 있다) 여기서는
 * 포털 루트 기준으로만 받는다.
 *
 *   포털 index.php                         ''
 *   learn, moodle, studio, bluecart, access,
 *   slack/lists.php, slackai/lists.php     '../'
 *   slack/xxx/, slackai/xxx/ 하위 폴더      '../../'
 *   dti                                    $page['base'] . '../'
 *
 * sw.js 는 포털 루트에 있어야 한다. service worker 는 **자기 파일이 있는 폴더
 * 아래만** 맡을 수 있어서, 루트에 두어야 모든 모듈 페이지에서 같은 하나가 쓰인다.
 * 그래야 포털에서 한 번 "알림 받기" 를 누르면 어느 모듈을 열어 두었든 알림이 온다.
 */

if (!function_exists('pwa_head_tags')) {
    function pwa_head_tags(string $toRoot = ''): string
    {
        $r  = htmlspecialchars($toRoot, ENT_QUOTES, 'UTF-8');
        $js = dirname(__DIR__) . '/pwa.js';
        $v  = is_file($js) ? filemtime($js) : 0;
        return '<link rel="manifest" href="' . $r . 'manifest.webmanifest">' . "\n"
            . '<meta name="theme-color" content="#1C5DE5">' . "\n"
            . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-title" content="iWorks">' . "\n"
            . '<link rel="apple-touch-icon" href="' . $r . 'styles/apple-touch-icon.png">' . "\n"
            . '<script src="' . $r . 'pwa.js?v=' . $v . '" defer data-root="' . $r . '"></script>' . "\n";
    }
}
