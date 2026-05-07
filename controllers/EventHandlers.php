<?php

namespace Rhymix\Modules\Oembed\Controllers;

use Rhymix\Modules\Oembed\Models\Config as ConfigModel;
use Rhymix\Modules\Oembed\Models\Registry;
use Rhymix\Framework\Filters\MediaFilter;
use BaseObject;
use Context;
use EditorModel;
use FileController;
use FileModel;
use ModuleModel;

class EventHandlers extends Base
{
  private const WRITE_ACTS = [
    'dispBoardWrite', 'dispBoardWriteComment', 'dispBoardReplyComment', 'dispBoardModifyComment',
  ];
  private const VIEW_ACTS = [
    'dispBoardContent', 'dispDocumentPrint', 'dispDocumentPreview',
  ];

  /**
   * before display 트리거.
   *
   * 글쓰기/댓글 페이지에서는 paste 훅(_ckeditor.js) 을 주입한다.
   * 글 보기 페이지에서는 (1) style.css, (2) `_render.js` + provider 자산 맵을
   * 주입한다. `_render.js` 는 DOMContentLoaded 시점에 본문 DOM 을 스캔해
   * 매칭된 외부 SDK 를 `document.head` 에 동적 삽입한다.
   *
   * 서버측 `before display` 단계에서 본문 markup 을 스캔하지 않는 이유:
   * `before display` 는 `before_display_content` addon 보다 먼저 발사되며
   * (DisplayHandler.class.php:69 vs :73-76), 동일 트리거에 등록된 다른
   * 모듈(editor 모듈의 transComponent 등) 의 실행 순서도 보장되지 않는다.
   * 본문이 최종 형태로 굳어지기 전 시점이라 marker 누락 가능성이 있어,
   * DOM 이 확정된 클라이언트 시점에 검사하는 편이 견고하다.
   */
  public function injectEditorAssets(&$content)
  {
    if (Context::getResponseMethod() !== 'HTML') {
      return;
    }

    // act 정규화 — Rhymix 는 URL 에 act 가 없을 때 ModuleHandler 가 module 의
    // default_index_act 로 자동 라우팅하지만 Context::get('act') 자체에는
    // 그 값을 채우지 않는다 (ModuleHandler.class.php:355-358). 그래서 mid 만
    // 가지고 들어온 dispBoardContent 등이 화이트리스트와 매칭되지 않는다.
    // current_module_info 는 트리거 호출 시점에 이미 세팅되어 있으므로,
    // act 가 비어 있으면 모듈의 default/admin index act 를 폴백으로 채운다.
    $act = Context::get('act');
    if (!$act) {
      $cmi = Context::get('current_module_info');
      if ($cmi && !empty($cmi->module)) {
        $xml = ModuleModel::getModuleActionXml($cmi->module);
        if ($xml) {
          $act = $xml->default_index_act ?? ($xml->admin_index_act ?? '');
        }
      }
    }
    $isWriteAct = in_array($act, self::WRITE_ACTS, true);
    $isViewAct = in_array($act, self::VIEW_ACTS, true);
    if (!$isWriteAct && !$isViewAct) {
      return;
    }

    $modulePath = '/modules/oembed/';
    $skin = ConfigModel::getSkin();
    $skinCssPath = $modulePath . 'skins/' . $skin . '/card.css';

    if ($isWriteAct) {
      $mid = Context::get('mid');
      if (!$mid) {
        return;
      }
      $moduleInfo = ModuleModel::getModuleInfoByMid($mid);
      $moduleSrl = isset($moduleInfo->module_srl) ? (int) $moduleInfo->module_srl : 0;
      if (!$moduleSrl) {
        return;
      }
      $editorConfig = EditorModel::getEditorConfig($moduleSrl);
      $editorSkin = $act === 'dispBoardWrite'
        ? ($editorConfig->editor_skin ?? '')
        : ($editorConfig->comment_editor_skin ?? '');
      if ($editorSkin !== 'editorxe') {
        return;
      }
      Context::loadFile(array($modulePath . 'tpl/css/style.css', '', '', null), true);
      if (is_file(\RX_BASEDIR . ltrim($skinCssPath, '/'))) {
        Context::loadFile(array($skinCssPath, '', '', null), true);
      }
      // style.css 의 editor 전용 규칙은 wysiwyg 영역 (iframe / divarea) 안에서만
      // 의미가 있고, Context::addCssFile 로 외부 로드 시 Rhymix 가 minify
      // 산출물의 mtime 으로 ?t= 캐시버스터를 박는데 그 mtime 이 stale 하게 굳는
      // 환경에서는 CKEditor 가 옛 URL 만 contentsCss 로 전달해 갱신이 안 된다.
      // 매 요청마다 파일 내용을 그대로 읽어 inline payload 로 emit 하고,
      // _editorxe.js 가 CKEDITOR.addCss + 인스턴스 document 에 직접 <style>
      // 주입한다.
      $editorCssFullPath = \RX_BASEDIR . 'modules/oembed/tpl/css/style.css';
      if (is_file($editorCssFullPath)) {
        $editorCssContent = (string) file_get_contents($editorCssFullPath);
        Context::addHtmlHeader('<script>window.oembedEditorCss=' . json_encode($editorCssContent, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>');
      }
      Context::loadFile(array($modulePath . 'tpl/js/_editorxe.js', 'body', '', null), true);
      Context::addHtmlHeader('<script>window.current_mid=' . json_encode((string) $mid) . ';</script>');
      return;
    }

    // VIEW_ACTS — 본문에 카드/임베드 마크업이 노출되는 페이지
    Context::loadFile(array($modulePath . 'tpl/css/style.css', '', '', null), true);
    if (is_file(\RX_BASEDIR . ltrim($skinCssPath, '/'))) {
      Context::loadFile(array($skinCssPath, '', '', null), true);
    }

    $assets = $this->collectProviderEmbedAssets();
    $compatibleMode = ConfigModel::isCompatibleMode();
    if ($assets === [] && !$compatibleMode) {
      return;
    }

    // _render.js 는 DOM 스캔 후 매칭된 SDK 를 head 에 주입한다.
    // 어떤 provider 의 selector 가 어떤 script URL 로 매핑되는지는 이
    // inline payload 로 전달하므로, _render.js 자체는 데이터를 모른다.
    //
    // 호환 모드의 lazy iframe (.media_embed_wrapper iframe[data-src]) 활성화
    // 시 host 검증에 쓸 화이트리스트 정규식을 함께 노출. MediaFilter 가
    // 반환하는 PCRE 는 `%...%` delimiter 형태이므로 양 끝 한 글자씩 떼어
    // JS RegExp 본문으로 변환한다 (현재 구현은 항상 '%' delimiter 를 사용).
    // 호환 모드 OFF 인 경우 payload 자체를 보내지 않아 _render.js 가
    // fail-closed.
    $iframeWhitelist = '';
    if ($compatibleMode) {
      $regex = MediaFilter::getWhitelistRegex();
      if ($regex !== '' && strlen($regex) >= 2) {
        $iframeWhitelist = substr($regex, 1, -1);
      }
    }
    Context::addHtmlHeader(sprintf(
      '<script>window.oembedEmbedAssets=%s;window.oembedCompatibleMode=%s;window.oembedIframeWhitelist=%s;</script>',
      json_encode($assets, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
      $compatibleMode ? 'true' : 'false',
      json_encode($iframeWhitelist, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
    ));
    Context::loadFile(array($modulePath . 'tpl/js/_render.js', 'body', '', null), true);

    if($compatibleMode) {
      $previewFolder = \RX_BASEDIR . 'modules/preview/';
      Context::loadFile(array($previewFolder . 'tpl/css/media_embed.css', '', '', null), true);
      Context::loadFile(array($previewFolder . 'skins/default/preview_card_style.css', '', '', null), true);
    }
  }

  /**
   * 글/댓글 저장 직후 본문을 검사해 oembed 카드에서 제거된 OG 이미지 첨부를
   * 정리한다.
   *
   * 등록 시점 (procOembedFetch → ImageAttacher::attach) 에 file 모듈에 정상
   * 첨부로 들어가지만, 사용자가 카드를 본문에서 지우면 첨부 row 만 남아
   * setFilesValid 가 그대로 isvalid='Y' 로 박제한다. 후크는
   * *.insertDocument / *.updateDocument / *.insertComment /
   * *.updateComment 의 **after**-trigger 에 묶여 있다.
   *
   * before 가 아닌 after 인 이유: before 시점은 본문 검증·보안 검사·
   * extra_vars 업로드 등이 진행되기 전이라 그 단계가 throw 하면 첨부 물리
   * 파일만 삭제된 채 문서는 원래대로 롤백돼 기존 카드 이미지가 깨진다
   * (Storage::delete 는 비트랜잭셔널). after 는 setFilesValid 가 성공한 직후
   * · $oDB->commit() 직전이라 거의 모든 실패 경로를 통과한 뒤다. 이론상
   * commit 자체가 실패하면 같은 문제가 생길 수 있지만 그 확률은 무시 가능.
   *
   * setFilesValid 가 직전에 oembed 첨부의 isvalid 를 'Y' 로 바꿔 놓지만,
   * 우리 prune 은 file_srl 로 deleteFile 을 호출해 row 자체를 지우므로
   * isvalid 값은 영향 없다.
   *
   * oembed 첨부 식별은 source_filename 의 `oembed_<16hex>.<ext>` 패턴으로
   * 한다. 별도 마커 컬럼(예: upload_target_type='oembed_image')을 두는
   * 방식은 setFilesValid 가 매 저장마다 그 컬럼을 'doc' 로 덮어쓰기 때문에
   * 재편집 시점에는 표식이 사라져 식별이 안 된다. ImageAttacher 가
   * 발급하는 source_filename 은 어떤 흐름에서도 변경되지 않으므로 신규
   * 저장과 N회 재편집 모두에서 일관되게 매칭된다. 일반 업로드 첨부는
   * 이 패턴과 충돌할 가능성이 사실상 없다 (16자리 16진수).
   *
   * 흐름:
   *   1. 본문에서 data-oembed-file-srl="N" 속성을 모두 추출 → 참조 set 구성
   *   2. upload_target_srl 의 모든 파일 중 source_filename 이 oembed 패턴인
   *      파일을 후보로 본다
   *   3. 참조 set 에 없는 후보 → deleteFile 로 회수
   *
   * 사용자가 source-edit 으로 data-oembed-file-srl 속성을 임의로 지우면
   * 그 첨부도 함께 정리된다 — 의도된 trade-off (속성이 깨진 카드는
   * 본문에서도 동작하지 않으므로 첨부와의 일관성이 더 중요).
   */
  public function pruneOrphanedOembedFiles(&$obj)
  {
    $uploadTargetSrl = (int) ($obj->document_srl ?? 0);
    if (!$uploadTargetSrl) {
      $uploadTargetSrl = (int) ($obj->comment_srl ?? 0);
    }
    if (!$uploadTargetSrl) {
      return new BaseObject();
    }

    $referenced = [];
    $content = (string) ($obj->content ?? '');
    if ($content !== '' && preg_match_all('/data-oembed-file-srl="(\d+)"/', $content, $matches)) {
      $referenced = array_flip(array_map('intval', $matches[1]));
    }

    $files = FileModel::getFiles(
      $uploadTargetSrl,
      ['file_srl', 'source_filename']
    );
    if (!is_array($files) || $files === []) {
      return new BaseObject();
    }

    $oFileController = FileController::getInstance();
    foreach ($files as $file) {
      $fileSrl = (int) ($file->file_srl ?? 0);
      if ($fileSrl <= 0) {
        continue;
      }
      // ImageAttacher 가 발급한 패턴 (FilenameFilter::clean 통과 후 형태 그대로).
      // FileModel::getFiles 는 source_filename 을 escape() 처리해서 내려보내지만
      // [a-z0-9_.] 만 들어 있는 우리 파일명은 escape 후에도 동일하다.
      $name = (string) ($file->source_filename ?? '');
      if (!preg_match('/^oembed_[0-9a-f]{16}\.[a-z0-9]+$/i', $name)) {
        continue;
      }
      if (isset($referenced[$fileSrl])) {
        continue;
      }
      $oFileController->deleteFile($fileSrl);
    }

    return new BaseObject();
  }

  /**
   * 등록된 모든 provider(비활성화 포함) 에서 client-side SDK 자산을 모은다.
   * disabled_providers 는 paste 단계 게이트일 뿐, 이미 저장된 본문은 계속
   * 정상 렌더되어야 하므로 view 시점엔 전체 provider 를 검사한다.
   *
   * crossorigin 은 SDK CDN 이 CORS 헤더를 보낼 때만 true. CORS 미지원
   * SDK (Instagram embed.js / X widgets.js 등) 에 anonymous 모드를 강제하면
   * 브라우저가 스크립트 로딩을 차단한다.
   *
   * normalize 는 sanitizer 가 떨어뜨린 클래스를 view 시점에 복원하기 위한
   * detect→addClass 규칙. SDK 검사보다 먼저 적용된다.
   *
   * @return array<int, array{
   *   selector: string,
   *   script: string,
   *   crossorigin: bool,
   *   normalize: array<int, array{detect: string, addClass: string}>,
   * }>
   */
  private function collectProviderEmbedAssets(): array
  {
    $assets = [];
    foreach (Registry::getProviders(false) as $provider) {
      foreach ($provider->getEmbedAssets() as $asset) {
        $selector = isset($asset['selector']) ? (string) $asset['selector'] : '';
        $script = isset($asset['script']) ? (string) $asset['script'] : '';
        if ($selector === '' || $script === '') {
          continue;
        }
        $normalize = [];
        if (isset($asset['normalize']) && is_array($asset['normalize'])) {
          foreach ($asset['normalize'] as $rule) {
            $detect = isset($rule['detect']) ? (string) $rule['detect'] : '';
            $addClass = isset($rule['addClass']) ? (string) $rule['addClass'] : '';
            if ($detect !== '' && $addClass !== '') {
              $normalize[] = ['detect' => $detect, 'addClass' => $addClass];
            }
          }
        }
        $assets[] = [
          'selector' => $selector,
          'script' => $script,
          'crossorigin' => !empty($asset['crossorigin']),
          'normalize' => $normalize,
        ];
      }
    }
    return $assets;
  }
}
