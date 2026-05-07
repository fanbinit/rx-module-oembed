/**
 * oembed - CKEditor 4 paste hook.
 *
 * 사용자가 URL 을 붙여넣으면 procOembedFetch 를 호출해 임베드/카드로
 * 변환된 wrapped_html 을 받아 CKEditor 의 dom API 로 노드를 교체한다.
 * 실패한 호스트는 sessionStorage 에 기록해 동일 호스트 재시도를 차단한다.
 *
 * 임베드 HTML 은 서버에서 화이트리스트 기반(htmlspecialchars + 정규화된
 * provider 출력)으로 생성하므로, CKEditor 의 createFromHtml/replace 를
 * 통해 자체 sanitizer 를 거쳐 삽입한다. innerHTML 을 직접 다루지 않는다.
 */
(function (window) {
  'use strict';

  if (!window.CKEDITOR) {
    return;
  }

  var URL_PATTERN = /(?:https?:)?\/\/[^\s<>"]+/i;
  var FAILED_HOSTS_KEY = 'oembed:failed_hosts';
  var FAILED_HOST_TTL_MS = 60 * 60 * 1000; // 1 시간
  var DEBUG = !!(window.localStorage && window.localStorage.getItem('oembed:debug'));

  function debug() {
    if (!DEBUG || !window.console) {
      return;
    }
    var args = ['[oembed]'].concat(Array.prototype.slice.call(arguments));
    (window.console.debug || window.console.log).apply(window.console, args);
  }

  function loadFailedHosts() {
    try {
      var raw = window.sessionStorage.getItem(FAILED_HOSTS_KEY);
      var failed = raw ? JSON.parse(raw) : {};
      // FAILED_HOST_TTL_MS 가 지난 항목은 만료 처리해 자동 재시도 가능하게 한다.
      // 일시적인 네트워크 실패나 CSRF 토큰 미세팅 같은 환경 문제로 한 번
      // 등록되면 영구 차단되는 부작용을 막는다.
      var now = Date.now();
      var changed = false;
      for (var host in failed) {
        if (Object.prototype.hasOwnProperty.call(failed, host)) {
          if (typeof failed[host] !== 'number' || (now - failed[host]) > FAILED_HOST_TTL_MS) {
            delete failed[host];
            changed = true;
          }
        }
      }
      if (changed) {
        window.sessionStorage.setItem(FAILED_HOSTS_KEY, JSON.stringify(failed));
      }
      return failed;
    } catch (e) {
      return {};
    }
  }

  function rememberFailedHost(host) {
    try {
      var failed = loadFailedHosts();
      failed[host] = Date.now();
      window.sessionStorage.setItem(FAILED_HOSTS_KEY, JSON.stringify(failed));
    } catch (e) {
      /* sessionStorage 가 차단된 환경 */
    }
  }

  function hostOf(url) {
    try {
      return new URL(url, window.location.href).host;
    } catch (e) {
      return '';
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function csrfToken() {
    // Rhymix 는 common.js 에서 Rhymix.getCSRFToken() 으로 토큰을 노출한다.
    // 일부 환경 (admin 화면 일부) 은 window.csrf_token 으로도 들어 있어 폴백.
    if (window.Rhymix && typeof window.Rhymix.getCSRFToken === 'function') {
      return (window.Rhymix.getCSRFToken() || '').toString();
    }
    return (window.csrf_token || '').toString();
  }

  function fetchOembed(url, editorSequence) {
    var body = new window.URLSearchParams();
    body.append('url', url);
    body.append('mid', window.current_mid || '');
    if (editorSequence) {
      // OG 카드의 이미지를 file 모듈을 통해 본문 첨부로 등록할 때 필요하다.
      // 임베드 흐름엔 영향 없음 — 매칭 성공 시 서버측에서 무시한다.
      body.append('editor_sequence', String(editorSequence));
    }
    var token = csrfToken();
    if (token) {
      body.append('_rx_csrf_token', token);
    }
    return window.fetch('/index.php?module=oembed&act=procOembedFetch', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': token,
        'Accept': 'application/json'
      },
      body: body.toString()
    }).then(function (res) {
      return res.ok ? res.json() : { kind: 'fail' };
    }).catch(function () {
      return { kind: 'fail' };
    });
  }

  function pickPastedUrl(data) {
    if (!data) {
      return null;
    }
    // CKEditor 4 는 paste 시 dataValue 를 <p>...</p> 등으로 감싸 넣고 끝에 개행을
    // 남기는 경우가 많다. 태그 + 공백류 제거 후 단일 URL 만 남았는지 비교한다.
    // URL_PATTERN 자체가 \s 를 배제하므로, 비교 대상도 공백 제거된 형태.
    var text = (data.dataValue || '').replace(/<[^>]+>/g, '').replace(/\s+/g, '');
    if (!text) {
      return null;
    }
    var match = text.match(URL_PATTERN);
    if (!match || match[0] !== text) {
      debug('skip non-url paste:', text);
      return null;
    }
    return match[0];
  }

  function placeholderHtml(url) {
    var safe = escapeHtml(url);
    return '<p data-oembed-pending="1"><a href="' + safe + '">' + safe + '</a></p>';
  }

  function findPlaceholder(editor, url) {
    var doc = editor.document;
    if (!doc) {
      return null;
    }
    var nodeList = doc.find('p[data-oembed-pending="1"]');
    var count = nodeList.count();
    for (var i = 0; i < count; i++) {
      var node = nodeList.getItem(i);
      if ((node.getText() || '').trim() === url) {
        return node;
      }
    }
    return null;
  }

  function replacePlaceholder(editor, url, html) {
    var node = findPlaceholder(editor, url);
    if (!node) {
      return false;
    }
    var newNode = window.CKEDITOR.dom.element.createFromHtml(html, editor.document);
    newNode.replace(node);
    // 임베드 바로 다음 줄에 커서를 둘 수 있도록 빈 단락을 끼워 넣는다.
    // contenteditable=false 인 위젯 직후엔 caret 진입이 까다로워서 후속 입력이
    // 위젯 내부로 빨려 들어가거나 아예 막히는 케이스를 방지한다.
    var spacer = window.CKEDITOR.dom.element.createFromHtml('<p>&nbsp;</p>', editor.document);
    spacer.insertAfter(newNode);
    return true;
  }

  function removePlaceholder(editor, url) {
    var node = findPlaceholder(editor, url);
    if (node) {
      node.removeAttribute('data-oembed-pending');
    }
  }

  function handlePaste(editor, evt) {
    var url = pickPastedUrl(evt.data);
    if (!url) {
      return;
    }
    var host = hostOf(url);
    if (host && loadFailedHosts()[host]) {
      debug('skip blacklisted host:', host);
      return;
    }
    debug('paste url:', url);

    evt.data.dataValue = placeholderHtml(url);

    var editorSequence = (editor && editor.config && editor.config.xe_editor_sequence) || 0;
    fetchOembed(url, editorSequence).then(function (resp) {
      if ((resp.kind === 'embed' || resp.kind === 'card') && resp.wrapped_html) {
        var replaced = replacePlaceholder(editor, url, resp.wrapped_html);
        debug(replaced ? 'replace placeholder:' : 'placeholder gone, dropping response:', resp.kind, url);
        if (replaced) {
          syncUploadTargetSrl(editorSequence, resp.upload_target_srl);
        } else if (resp.file_srl) {
          // placeholder 가 응답 도착 전에 제거됐다 (Ctrl+Z 등). 서버에서 이미 등록된
          // OG 이미지 첨부가 고아로 남아 글 저장 시 따라붙지 않도록 회수한다.
          abortAttachment(editorSequence, resp.file_srl);
        }
      } else {
        debug('fetch failed:', url, resp);
        // 서버가 provider 식별자를 함께 내려주면 호스트는 알려진 provider 가
        // 처리하는 도메인이고 이번 실패는 transient (예: Facebook share URL 의
        // redirect resolve 일시 차단) 라는 신호다. 1시간 짜리 host blacklist 에
        // 올리면 그 사이 같은 호스트의 다른 URL paste 도 조용히 차단되므로,
        // 이 경우엔 placeholder 만 제거하고 다음 paste 에서 다시 시도하게 둔다.
        if (host && !resp.provider) {
          rememberFailedHost(host);
        }
        removePlaceholder(editor, url);
      }
    });
  }

  function abortAttachment(editorSequence, fileSrl) {
    if (!editorSequence || !fileSrl) {
      return;
    }
    var body = new window.URLSearchParams();
    body.append('editor_sequence', String(editorSequence));
    body.append('file_srl', String(fileSrl));
    body.append('mid', window.current_mid || '');
    var token = csrfToken();
    if (token) {
      body.append('_rx_csrf_token', token);
    }
    // procFileDelete 는 같은 editor_sequence 의 세션 upload_target_srl 에 매칭되는
    // 파일만 삭제하므로 다른 사용자의 첨부를 건드릴 위험은 없다.
    window.fetch('/index.php?module=file&act=procFileDelete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': token,
        'Accept': 'application/json'
      },
      body: body.toString()
    }).catch(function () {
      /* 회수 실패해도 사용자 흐름엔 영향 없음 — isvalid='N' 으로 cron 정리 대상 */
    });
  }

  // 서버측에서 OG 이미지를 file 모듈 첨부로 등록한 경우, jquery.fileupload main.js
  // 와 동일하게 폼의 primary key 입력값(document_srl)을 새 upload_target_srl 로
  // 맞춰 둔다. 글 저장 시 document.controller 가 같은 srl 로 row 를 만들어야
  // setFilesValid 가 첨부를 연결한다.
  // - 신규 글: primary.value 가 빈값/"0" 이므로 새로 발급된 srl 로 갱신.
  // - 편집 글: 서버측 attach 가 세션의 기존 upload_target_srl(=document_srl)
  //   을 그대로 재사용하므로 같은 값을 다시 넣을 뿐 영향 없음.
  function syncUploadTargetSrl(editorSequence, uploadTargetSrl) {
    if (!editorSequence || !uploadTargetSrl) {
      return;
    }
    var rel = window.editorRelKeys && window.editorRelKeys[editorSequence];
    if (rel && rel.primary) {
      rel.primary.value = uploadTargetSrl;
    }
  }

  function attach(editor) {
    if (editor._oembedAttached) {
      return;
    }
    editor._oembedAttached = true;
    editor.on('paste', function (e) {
      handlePaste(editor, e);
    });
  }

  // style.css 본문은 EventHandlers 가 매 요청마다 파일을 읽어 inline 으로
  // window.oembedEditorCss 에 박아 넣는다. 외부 CSS 로드의 ?t= 캐시버스터가
  // stale mtime 으로 굳는 환경을 우회하기 위함이다.
  // - 신규 인스턴스: CKEDITOR.addCss 가 wysiwyg 스타일시트 버퍼에 누적해 둔다.
  // - 기존 인스턴스: 그 버퍼는 이미 비워져서, 인스턴스의 document 에 직접
  //   <style> 을 추가해야 적용된다.
  function injectCssIntoInstance(editor) {
    if (editor._oembedCssInjected) {
      return;
    }
    var css = window.oembedEditorCss;
    if (typeof css !== 'string' || !css) {
      return;
    }
    var doc = editor.document && editor.document.$;
    if (!doc) {
      return;
    }
    var style = doc.createElement('style');
    style.setAttribute('type', 'text/css');
    style.appendChild(doc.createTextNode(css));
    var head = doc.head || doc.getElementsByTagName('head')[0] || doc.documentElement;
    if (head) {
      head.appendChild(style);
      editor._oembedCssInjected = true;
    }
  }

  if (typeof window.CKEDITOR.addCss === 'function' && typeof window.oembedEditorCss === 'string' && window.oembedEditorCss) {
    window.CKEDITOR.addCss(window.oembedEditorCss);
  }

  // 새로 생성되는 인스턴스
  window.CKEDITOR.on('instanceReady', function (ev) {
    attach(ev.editor);
    injectCssIntoInstance(ev.editor);
  });

  // _ckeditor.js 가 인스턴스 생성 후에 로드되는 경우 (Rhymix XeCkEditor 가 직접
  // 인스턴스를 만든 뒤 자산을 잇따라 로드하는 시나리오) 를 위한 백업.
  if (window.CKEDITOR.instances) {
    for (var name in window.CKEDITOR.instances) {
      if (Object.prototype.hasOwnProperty.call(window.CKEDITOR.instances, name)) {
        attach(window.CKEDITOR.instances[name]);
        injectCssIntoInstance(window.CKEDITOR.instances[name]);
      }
    }
  }
})(window);
