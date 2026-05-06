<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;
use Rhymix\Modules\Oembed\Models\RemoteFetcher;

/**
 * pixiv 일러스트 임베드.
 *
 * 공개 작품: embed.pixiv.net iframe 으로 직접 표시.
 * 비공개(로그인 필요) 작품: embed 페이지 HTML 에 <a class="root"> 뒤로
 *   <img> 대신 <div> 가 오면 접근 불가로 판단해 빈 문자열을 반환하고,
 *   컨트롤러가 OG 카드 경로로 폴백하게 한다.
 *
 * 접근 가능 여부 판별은 fetchInfo 에서 수행해 인스턴스 변수에 캐시하고,
 * buildEmbed 가 그 결과를 참조한다. fetchInfo 가 호출되지 않은 경우(직접
 * 사용 등)에는 buildEmbed 가 직접 판별한다.
 */
class Pixiv extends Provider
{
  public string $name = 'Pixiv';
  public string $type = self::TYPE_SOCIAL;
  public bool $oembed = false;
  public array $hosts = ['www.pixiv.net', 'pixiv.net'];
  // artworks 형식: /artworks/{id} (선택적 로케일 prefix /en/, /ja/ 등 포함)
  // member_illust 형식: /member_illust.php?...&illust_id={id}&...
  // (illust_id 파라미터 위치는 쿼리 내 어디에나 올 수 있음)
  public array $patterns = [
    '~(?:https?:)?//(?:www\.)?pixiv\.net/(?:[a-z]{2}/)?artworks/(\d+)~i' => ['illust_id'],
    '~(?:https?:)?//(?:www\.)?pixiv\.net/member_illust\.php\?[^#]*illust_id=(\d+)~i' => ['illust_id'],
  ];

  /** embed 페이지 접근 가능 여부 캐시 (null=미확인, true=가능, false=불가) */
  private ?bool $accessible = null;

  /**
   * embed.pixiv.net 페이지를 가져와 <a class="root"> 직후에
   * <img> 가 오는지 확인해 접근 가능 여부를 반환한다.
   */
  private function checkAccessibility(string $illustId): bool
  {
    $embedUrl = 'https://embed.pixiv.net/oembed_iframe.php?type=illust&id=' . rawurlencode($illustId);
    $fetched = RemoteFetcher::fetchHtml($embedUrl);
    if ($fetched === null) {
      return false;
    }
    // 공개 작품: <a class="root"...><img ...> 패턴 (이미지 직접 표시)
    // 비공개 작품: <a class="root"...><div ...> 패턴 (로그인 유도 UI)
    // 주의: pixiv 가 embed 페이지 HTML 구조를 변경할 경우 이 패턴도 함께
    // 갱신해야 한다. 매칭 실패 시 비공개로 간주해 OG 카드로 폴백한다.
    return (bool) preg_match(
      '~<a\b[^>]*\bclass=["\'][^"\']*\broot\b[^"\']*["\'][^>]*>\s*<img\b~i',
      $fetched['body']
    );
  }

  public function fetchInfo(string $url): ?array
  {
    // URL 에서 illust_id 를 재추출해 embed 페이지 접근 가능 여부를 확인한다.
    // 결과를 인스턴스 변수에 캐시해 buildEmbed 가 이중 fetch 하지 않도록 한다.
    if (!preg_match('~(?:artworks/|illust_id=)(\d+)~i', $url, $m)) {
      $this->accessible = false;
      return null;
    }
    $this->accessible = $this->checkAccessibility($m[1]);
    // 공개 API 없음 — width/height/thumbnail_url 은 제공하지 않는다.
    return null;
  }

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $illustId = $matchData['captures']['illust_id'] ?? '';
    if ($illustId === '') {
      return '';
    }

    // fetchInfo 가 호출되지 않은 경우(직접 호출 등) 여기서 확인한다.
    if ($this->accessible === null) {
      $this->accessible = $this->checkAccessibility($illustId);
    }

    // 비공개 작품 → 빈 문자열 반환 → 컨트롤러가 OG 카드로 폴백.
    if (!$this->accessible) {
      return '';
    }

    [$w, $h] = $this->getDimensions($width, $height);
    $embedUrl = 'https://embed.pixiv.net/oembed_iframe.php?type=illust&id=' . rawurlencode($illustId);
    // TYPE_SOCIAL 기본 비율(4:3)을 단일 숫자로 출력.
    $ratio = $h > 0 ? (string) round($w / $h, 4) : (string) round(4 / 3, 4);
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" scrolling="no" loading="lazy"></iframe>',
      htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function getEmbedHosts(): array
  {
    // buildEmbed 가 출력하는 iframe src 의 호스트만 화이트리스트에 필요.
    return ['embed.pixiv.net'];
  }
}
