<?php

namespace Rhymix\Modules\Oembed\Models;

abstract class Provider
{
  public const TYPE_MULTIMEDIA = 'multimedia';
  public const TYPE_SOCIAL = 'social';

  public string $name = '';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  /**
   * paste 시점에 입력 URL 의 호스트와 매칭되는 후보 목록.
   * 시스템 → 보안 → 외부 멀티미디어 허용에 등록해야 하는 호스트는 이 목록이
   * 아니라 getEmbedHosts() 의 결과다 (둘은 자주 다르다 — 예: X 는 입력은
   * twitter.com / x.com 이지만 widgets.js 가 만드는 iframe 은
   * platform.twitter.com 호스팅이라 화이트리스트에는 후자가 등록돼야 한다).
   */
  public array $hosts = [];

  /**
   * Pattern map.
   *
   * key   : PCRE pattern (e.g. '#youtube\.com/watch\?v=([\w-]+)#i')
   * value : list of capture-group names whose order matches the pattern.
   *
   * Example: ['#youtube\.com/watch\?v=([\w-]+)#i' => ['video_id']]
   */
  public array $patterns = [];

  /**
   * Build the final embed HTML for matched data.
   *
   * @param array{pattern: string, captures: array<string,string>, url: string} $matchData
   */
  abstract public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string;

  /**
   * Try to match the given URL against this provider's pattern map.
   * Returns null on miss, otherwise a struct describing the match.
   *
   * @return array{pattern: string, captures: array<string,string>, url: string}|null
   */
  public function match(string $url): ?array
  {
    foreach ($this->patterns as $pattern => $captureNames) {
      if (preg_match($pattern, $url, $m)) {
        $captures = [];
        foreach ($captureNames as $i => $key) {
          $captures[$key] = $m[$i + 1] ?? '';
        }
        return ['pattern' => $pattern, 'captures' => $captures, 'url' => $url];
      }
    }
    return null;
  }

  /**
   * 매칭된 콘텐츠의 effective 타입을 돌려준다. 기본은 provider 의 정적 $type 을
   * 그대로 반환. 동일 provider 가 여러 콘텐츠 종류를 처리하는 경우(예: Facebook
   * — 일반 포스트는 social, 릴은 multimedia) 매칭별로 다른 타입을 돌려주도록
   * override 해서 wrapper 의 `data-oembed-type` 가 콘텐츠에 맞게 박히도록 한다.
   * 이 값은 글 보기 페이지의 CSS 분기 (social=라벨 박스, multimedia=라운드된
   * iframe) 에 직결된다.
   *
   * Controller 는 paste 응답의 wrapper 작성 시점에 한 번만 호출.
   *
   * @param array{pattern: string, captures: array<string,string>, url: string} $matchData
   */
  public function resolveType(array $matchData): string
  {
    return $this->type;
  }

  /**
   * URL 에 대해 외부 메타정보를 가져온다 (oEmbed endpoint, 자체 API 등).
   *
   * Controller 가 Registry::match hit 직후, buildEmbed 호출 전에 한 번 호출한다.
   * 반환값은 두 곳에 쓰인다:
   *   - width / height → buildEmbed 의 dimension 인자로 전달돼 iframe 비율을
   *     실제 영상 비율에 맞춤 (없으면 Provider::getDimensions 의 type 별 기본값).
   *   - thumbnail_url → ImageAttacher 입력으로 본문 첨부 파일 등록 (없으면 첨부 없음).
   *
   * 외부 호출은 반드시 RemoteFetcher 를 거쳐 SSRF 가드를 통과해야 한다.
   * 네트워크/파싱 실패는 모두 null 반환으로 흡수 — 호출자는 폴백한다.
   *
   * @return array{width?: int, height?: int, thumbnail_url?: string}|null
   */
  public function fetchInfo(string $url): ?array
  {
    return null;
  }

  /**
   * 본문에 이 provider 의 임베드 마크업이 박제되어 있을 때, 글 보기
   * 페이지에서 자동으로 로드해야 할 외부 SDK 목록.
   *
   * selector 는 클라이언트의 `document.querySelector` 에 직접 전달되는
   * CSS 선택자다. 본문 DOM 에 실제로 매칭되는 노드가 있을 때만 SDK 가
   * 주입되므로, 서버측 트리거-addon 실행 순서나 addon 의 본문 후처리에
   * 무관하게 정확하다. 결정은 DOMContentLoaded 이후 시점이라 본문 markup
   * 은 이미 최종 DOM 에 들어가 있다.
   *
   * crossorigin 은 선택. Facebook SDK 처럼 CDN 이 CORS 헤더를 보내고
   * 공식 스니펫이 `crossorigin="anonymous"` 를 요구하는 경우에만 true 로
   * 둔다. Instagram embed.js / X widgets.js 처럼 CORS 헤더가 없는 SDK
   * 에 anonymous 모드를 켜면 브라우저가 로딩을 차단한다 (기본 false).
   *
   * normalize 는 선택. CKEditor ACF 나 HTMLPurifier 가 저장 시점에 우리
   * 가 buildEmbed() 에서 출력한 클래스를 화이트리스트에서 제외해 떨어뜨릴
   * 수 있는데(Instagram blockquote 의 .instagram-media 가 대표적), SDK
   * 가 자기 클래스를 보고 변환하는 구조라 클래스가 빠지면 임베드가 사문화
   * 된다. 각 항목은 sanitizer 를 통과한 detect selector(예: 사용자 정의
   * data 속성처럼 일반적으로 살아남는 식별자) 가 매칭되는 노드에 addClass
   * 를 붙이도록 _render.js 에 지시한다. SDK 검사보다 먼저 적용된다.
   *
   * buildEmbed() 결과 안에 <script> 를 직접 넣으면 HTMLPurifier 가 저장
   * 단계에서 제거하므로(글 저장 자체가 실패), script 는 반드시 이 메서드를
   * 통해 view 시점에 head 로 주입돼야 한다.
   *
   * @return array<int, array{
   *   selector: string,
   *   script: string,
   *   crossorigin?: bool,
   *   normalize?: array<int, array{detect: string, addClass: string}>,
   * }>
   */
  public function getEmbedAssets(): array
  {
    return [];
  }

  /**
   * 본문에 박힐 iframe / SDK 변환 결과의 src 호스트 목록.
   *
   * 시스템 → 보안 → 외부 멀티미디어 허용 (MediaFilter 화이트리스트) 에
   * 등록되어야 출력 단계에서 iframe 이 살아남는 호스트들이다. paste 매칭에
   * 쓰이는 $hosts 와는 의미가 다르다:
   *   - $hosts 는 입력 URL 매칭 (paste 단계).
   *   - getEmbedHosts() 는 출력 호스트 (view 단계, MediaFilter 검사 대상).
   *
   * 예) X.php — $hosts 는 twitter.com / x.com 등 사용자가 paste 하는 URL,
   *           getEmbedHosts() 는 widgets.js 가 만드는 iframe 의 호스트인
   *           platform.twitter.com.
   *
   * 단순 iframe provider (Youtube 처럼 입력 도메인과 임베드 도메인이 같은
   * 경우) 는 이 메서드를 override 하지 않아도 되며, 기본은 $hosts 와 동일.
   * 어드민 화면은 이 결과를 그대로 “화이트리스트에 추가하세요” 안내에 쓴다.
   *
   * @return array<int, string>
   */
  public function getEmbedHosts(): array
  {
    return $this->hosts;
  }

  /**
   * Resolve final iframe dimensions.
   * If both axes are missing, fall back to type-aware defaults (multimedia=16:9, otherwise 4:3).
   *
   * @return array{0:int,1:int} [width, height]
   */
  public function getDimensions(?int $width, ?int $height): array
  {
    if ($width && $height) {
      return [$width, $height];
    }
    [$rw, $rh] = $this->type === self::TYPE_MULTIMEDIA ? [16, 9] : [4, 3];
    if ($width && !$height) {
      return [$width, (int) round($width * $rh / $rw)];
    }
    if ($height && !$width) {
      return [(int) round($height * $rw / $rh), $height];
    }
    $defaultWidth = 640;
    return [$defaultWidth, (int) round($defaultWidth * $rh / $rw)];
  }
}
