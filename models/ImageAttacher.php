<?php

namespace Rhymix\Modules\Oembed\Models;

use FileHandler;
use FileModel;
use FileController;

/**
 * OG 이미지를 외부 호스트에서 받아 file 모듈의 첨부 흐름에 태운다.
 *
 * 과거에는 모듈 전용 캐시 디렉터리(files/cache/oembed/images)에 직접
 * 저장했지만, 글에 종속되지 않는 별도 저장 경로를 만드는 것은
 * 운영 정책에 맞지 않아 제거했다. 지금은 `FileController::insertFile`
 * 을 통해 본문에 따라붙는 정상 첨부파일로 등록하고, 카드 마크업에는
 * 그 다운로드 URL 을 박는다. 글이 저장될 때 `setFilesValid` 가
 * upload_target_srl → document_srl 에 연결시켜 주므로 일반 첨부파일과
 * 동일한 라이프사이클을 따르게 된다.
 *
 * 호출은 `manual_insert=false` 로 한다 — 게시판/세션의 허용 확장자 ·
 * 파일 크기 제한 · adjustUploadedImage(EXIF 회전, 게시판별 포맷 변환
 * 등) 를 file 모듈이 통째로 적용한다. 임시 파일은 일반
 * `sys_get_temp_dir()` 가 아니라 `RX_BASEDIR . 'files/attach/chunks/'`
 * 에 둔다. file.controller.php:1061 가 이 prefix 를 인식해
 * `move_uploaded_file()` 대신 Storage::move 경로로 분기하기 때문에,
 * $_FILES 출처가 아닌 다운로드본도 동일 정책 검증을 거쳐 첨부될 수 있다.
 *
 * editor_sequence / module_srl / upload_target_srl 가 비어 있어
 * 첨부 등록이 불가능한 경우(에디터 외부 호출, 첨부 비활성 게시판 등)
 * 에는 null 을 돌려준다 — 호출측은 og.image 원본 URL 로 폴백한다.
 */
class ImageAttacher
{
  private const ALLOWED_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
  ];

  /**
   * Returns the attached file's URL plus its file_srl, or null on any failure.
   *
   * 호출측은 file_srl 을 응답에 실어 클라이언트로 보낸다 — 사용자가 Ctrl+Z
   * 등으로 placeholder 를 제거해 본문에 OG 이미지 카드가 박히지 못한 경우,
   * 클라이언트가 procFileDelete 로 이 첨부를 즉시 회수해 고아 파일이
   * 글에 따라가지 않도록 한다.
   *
   * @return array{url: string, file_srl: int}|null
   */
  public static function attach(string $imageUrl, int $editorSequence = 0, int $moduleSrl = 0): ?array
  {
    if ($imageUrl === '' || !preg_match('#^https?://#i', $imageUrl)) {
      return null;
    }
    if (!RemoteFetcher::isUrlSafe($imageUrl)) {
      return null;
    }
    if (!$editorSequence || empty($_SESSION['upload_info'][$editorSequence]->enabled)) {
      return null;
    }

    if (!$moduleSrl) {
      $moduleSrl = (int) ($_SESSION['upload_info'][$editorSequence]->module_srl ?? 0);
    }
    if (!$moduleSrl) {
      return null;
    }

    $image = RemoteFetcher::fetchImage($imageUrl);
    if ($image === null) {
      return null;
    }
    $mime = strtolower(trim(explode(';', $image['content_type'])[0]));
    $ext = self::ALLOWED_MIMES[$mime] ?? null;
    if ($ext === null) {
      return null;
    }

    // procFileUpload 와 동일한 규칙: 세션에 upload_target_srl 이 없으면
    // 새로 발급해서 박아 둔다. 글 저장 시점에 이 값이 document_srl 로 승격된다.
    $uploadTargetSrl = (int) ($_SESSION['upload_info'][$editorSequence]->upload_target_srl ?? 0);
    if (!$uploadTargetSrl) {
      $uploadTargetSrl = (int) \getNextSequence();
      $_SESSION['upload_info'][$editorSequence]->upload_target_srl = $uploadTargetSrl;
    }

    // file.controller.php:1061 가 chunks/ prefix 를 보고 Storage::move 분기로
    // 빠지도록 임시 파일을 chunks/ 에 둔다. 디렉터리는 chunked upload 가
    // 처음 일어날 때 만들어지지만, oembed 가 먼저 호출될 수도 있으므로
    // 보수적으로 makeDir 한다.
    $chunksDir = \RX_BASEDIR . 'files/attach/chunks/';
    if (!is_dir($chunksDir)) {
      FileHandler::makeDir($chunksDir);
    }
    $tmpName = $chunksDir . 'oembed_' . bin2hex(random_bytes(8));
    if (@file_put_contents($tmpName, $image['body']) === false) {
      @unlink($tmpName);
      return null;
    }

    $hash = substr(hash('sha256', $imageUrl . '|' . strlen($image['body'])), 0, 16);
    $sourceFilename = 'oembed_' . $hash . '.' . $ext;

    $fileInfo = [
      'name' => $sourceFilename,
      'tmp_name' => $tmpName,
      'size' => strlen($image['body']),
      'type' => $mime,
    ];

    $oFileController = FileController::getInstance();
    try {
      // manual_insert=false: 게시판/세션의 허용 확장자·파일 크기·총 첨부
      // 용량 정책을 file 모듈이 그대로 적용한다. 정책 위반은
      // msg_not_allowed_filetype / msg_exceeds_limit_size 등으로 던져지므로
      // 잡아서 OG 이미지 원본 URL 폴백으로 처리한다.
      $output = $oFileController->insertFile($fileInfo, $moduleSrl, $uploadTargetSrl, 0, false, $editorSequence);
    } catch (\Throwable $e) {
      @unlink($tmpName);
      return null;
    }

    // 성공 시 insertFile 가 chunks/ 에서 최종 위치로 옮겼으므로 unlink 는
    // no-op. 실패 시 원본이 남을 수 있으므로 보수적으로 정리한다.
    @unlink($tmpName);

    if (!is_object($output) || (method_exists($output, 'toBool') && !$output->toBool())) {
      return null;
    }

    // 별도 마커 컬럼을 두지 않고 source_filename 패턴(`oembed_<16hex>.<ext>`)
    // 자체를 oembed 첨부의 식별자로 쓴다. setFilesValid 가 매 저장마다
    // upload_target_type 을 'doc' 로 덮어쓰기 때문에 그 컬럼은 재편집 후에는
    // 표식 역할을 못한다. source_filename 은 insertFile 이후 어떤 흐름에서도
    // 변경되지 않으므로 1회 저장과 N회 재편집 모두에서 일관되게 매칭된다.
    // EventHandlers::pruneOrphanedOembedFiles 는 이 패턴으로 oembed 첨부 후보를
    // 골라내고, 본문의 data-oembed-file-srl 참조와 비교한다.

    // getDirectFileUrl 은 내부에서 이미 RX_BASEURL 을 붙여 준다.
    // getDownloadUrl 은 base 를 붙이지 않고 상대 경로만 돌려주므로 직접 prefix.
    if ($output->get('direct_download') === 'Y') {
      $url = FileModel::getDirectFileUrl($output->get('uploaded_filename'));
    } else {
      $url = \RX_BASEURL . FileModel::getDownloadUrl(
        $output->get('file_srl'),
        $output->get('sid'),
        0,
        $output->get('source_filename')
      );
    }
    return ['url' => (string) $url, 'file_srl' => (int) $output->get('file_srl')];
  }
}
