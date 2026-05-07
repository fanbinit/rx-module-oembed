/**
 * oembed - render-time SDK loader (view pages only).
 *
 * 글 보기 페이지에서 본문 DOM 에 박제된 임베드 마크업을 검사하고,
 * 매칭되는 provider SDK 를 `document.head` 에 동적으로 주입한다.
 *
 * - 자산 맵은 EventHandlers 가 inline `<script>` 로 head 에 emit 한
 *   `window.oembedEmbedAssets` 에 들어 있다
 *   ([{selector, script, crossorigin, normalize}, ...]).
 * - SDK <script> 를 본문에 함께 저장하면 HTMLPurifier 에 의해 글 저장이
 *   실패하므로, 임베드 markup 자체에는 SDK 가 없고 이 파일이 view 시점에
 *   채워 넣는 구조다.
 * - normalize 는 SDK 검사 직전에 적용한다. CKEditor ACF / HTMLPurifier 가
 *   blockquote 의 클래스를 떨어뜨리는 환경에서, 살아남는 data 속성 등을
 *   anchor 로 SDK 가 요구하는 클래스를 다시 붙인다 (예: Instagram 의
 *   data-instgrm-permalink → .instagram-media).
 * - 호환 모드 (`window.oembedCompatibleMode === true`) 일 때는 추가로
 *   레거시 preview 모듈의 lazy iframe (<iframe data-src="...">) 도
 *   활성화한다.
 *
 * DOMContentLoaded 이후에 실행되므로 본문이 모듈/addon/sanitizer 모두를
 * 통과한 최종 형태로 DOM 에 들어가 있다 — 서버측 트리거 시점의 string
 * 스캔보다 안정적이다.
 */
(function (window, document) {
  'use strict';

  function loadSdk(asset, loaded) {
    var src = asset.script;
    if (loaded[src]) {
      return;
    }
    if (document.querySelector('script[data-oembed-sdk="' + src + '"]')) {
      loaded[src] = true;
      return;
    }
    var script = document.createElement('script');
    script.async = true;
    // crossOrigin 은 provider 가 명시한 경우에만 설정. CDN 이 CORS 헤더를
    // 보내지 않는 SDK (Instagram embed.js / X widgets.js 등) 에 anonymous
    // 모드를 강제하면 브라우저가 로딩을 차단한다.
    if (asset.crossorigin === true) {
      script.crossOrigin = 'anonymous';
    }
    script.setAttribute('data-oembed-sdk', src);
    script.src = src;
    (document.head || document.documentElement).appendChild(script);
    loaded[src] = true;
  }

  function applyNormalize(asset) {
    if (!Array.isArray(asset.normalize)) {
      return;
    }
    for (var i = 0; i < asset.normalize.length; i++) {
      var rule = asset.normalize[i];
      if (!rule || typeof rule.detect !== 'string' || typeof rule.addClass !== 'string' || rule.addClass === '') {
        continue;
      }
      try {
        var nodes = document.querySelectorAll(rule.detect);
        for (var j = 0; j < nodes.length; j++) {
          nodes[j].classList.add(rule.addClass);
        }
      } catch (e) {
        // 잘못된 detect selector 는 조용히 건너뛴다.
      }
    }
  }

  function activate() {
    var assets = Array.isArray(window.oembedEmbedAssets) ? window.oembedEmbedAssets : [];

    // pass 1: sanitizer 가 떨어뜨린 클래스를 복원. SDK 검사보다 먼저 해야
    // selector 에 매칭된다.
    for (var n = 0; n < assets.length; n++) {
      if (assets[n]) {
        applyNormalize(assets[n]);
      }
    }

    // pass 2: selector 매칭 노드가 있으면 SDK 를 head 에 주입.
    var loaded = {};
    for (var i = 0; i < assets.length; i++) {
      var asset = assets[i];
      if (!asset || typeof asset.selector !== 'string' || typeof asset.script !== 'string') {
        continue;
      }
      try {
        if (document.querySelector(asset.selector)) {
          loadSdk(asset, loaded);
        }
      } catch (e) {
        // 잘못된 selector 는 조용히 건너뛴다 — provider 추가 시 typo 가
        // 다른 SDK 로딩까지 막지 않도록 격리한다.
      }
    }

    if (window.oembedCompatibleMode === true) {
      // 다중 방어: (1) 신뢰 wrapper(.media_embed_wrapper) 안의 iframe 만,
      // (2) http(s) 또는 protocol-relative scheme 만, (3) 서버측 MediaFilter
      // 화이트리스트 host 정규식 통과한 것만 활성화한다. 검증 실패 시
      // data-src 자체를 제거해 이후 코드가 다시 승격하지 못하도록 한다.
      var whitelistRegex = null;
      if (typeof window.oembedIframeWhitelist === 'string' && window.oembedIframeWhitelist !== '') {
        try {
          whitelistRegex = new RegExp(window.oembedIframeWhitelist, 'i');
        } catch (e) {
          whitelistRegex = null;
        }
      }
      // fail-closed: 화이트리스트 payload 가 없거나 컴파일 실패면 어떤
      // data-src 도 활성화하지 않는다.
      var schemeOk = /^(?:https?:)?\/\/[^\/\\]/i;
      var lazyIframes = document.querySelectorAll('.media_embed_wrapper iframe[data-src]');
      for (var j = 0; j < lazyIframes.length; j++) {
        var iframe = lazyIframes[j];
        var dataSrc = iframe.getAttribute('data-src');
        if (!dataSrc || iframe.getAttribute('src')) {
          continue;
        }
        var allow = false;
        if (whitelistRegex && schemeOk.test(dataSrc)) {
          try {
            allow = whitelistRegex.test(dataSrc);
          } catch (e) {
            allow = false;
          }
        }
        if (allow) {
          iframe.setAttribute('src', dataSrc);
        } else {
          iframe.removeAttribute('data-src');
        }
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', activate);
  } else {
    activate();
  }
})(window, document);
