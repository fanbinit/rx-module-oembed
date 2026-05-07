<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;
use Rhymix\Modules\Oembed\Models\RemoteFetcher;

class Youtube extends Provider
{
  public string $name = 'YouTube';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['www.youtube.com', 'youtube.com', 'm.youtube.com', 'youtu.be'];
  // watch 패턴은 본문에 [^#] 가 들어가서 # delimiter 와 충돌하므로 ~ delimiter 사용.
  // 다른 패턴도 일관성 있게 ~ 로 통일했다 (delimiter 와 본문 문자 충돌 방지).
  public array $patterns = [
    '~(?:https?:)?//(?:www\.|m\.)?youtube\.com/watch\?(?:[^#]*&)?v=([\w-]{6,})~i' => ['video_id'],
    '~(?:https?:)?//youtu\.be/([\w-]{6,})~i' => ['video_id'],
    '~(?:https?:)?//(?:www\.|m\.)?youtube\.com/shorts/([\w-]{6,})~i' => ['video_id'],
    '~(?:https?:)?//(?:www\.|m\.)?youtube\.com/embed/([\w-]{6,})~i' => ['video_id'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $videoId = $matchData['captures']['video_id'] ?? '';
    if ($videoId === '') {
      return '';
    }
    [$w, $h] = $this->getDimensions($width, $height);
    $src = 'https://www.youtube.com/embed/' . rawurlencode($videoId);
    // aspect-ratio 는 CSS spec 상 <width>/<height> 도 받지만 Rhymix HTMLFilter
    // (common/framework/filters/HTMLFilter.php:504) 가 허용하는 값은 (a) 단일
    // number, (b) 사전정의 enum (16/9, 9/16, 4/3 등), (c) 키워드 뿐이라 임의
    // 비율 영상에서 <w>/<h> 표기는 sanitizer 단계에서 떨어진다. 단일 number 로
    // 출력하면 CSS spec 에서 가로/세로 비율로 해석되어 동일 효과 + sanitizer
    // CSS_Number 정의에 통과. max-width:100% + height:auto 가 함께 있으면
    // 컨테이너 폭이 좁아져도 비율을 유지하며 자동 축소된다.
    $ratio = $h > 0 ? (string) round($w / $h, 4) : '1.7778';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function getEmbedHosts(): array
  {
    // buildEmbed 가 출력하는 iframe 의 src 호스트만 화이트리스트에 필요.
    return ['www.youtube.com'];
  }

  public function fetchInfo(string $url): ?array
  {
    // 공식 oEmbed endpoint — 키 발급 불필요. width/height 은 영상의 실제 비율을
    // 반영해서 응답하므로(Shorts 9:16, 21:9 시네마틱 등 포함), 이 값을 그대로
    // iframe width/height 으로 쓰면 비율이 자동으로 맞는다. YouTube oEmbed 는
    // maxwidth 와 maxheight 가 *둘 다* 지정될 때만 그 한도를 적용한다 — 한쪽만
    // 주면 무시하고 356x200 같은 작은 기본값으로 응답한다.
    //
    // maxheight=720 인 이유: Shorts(9:16) 의 경우 height 가 width 의 cap 을
    // 결정한다. maxheight 가 480 이면 응답이 270x480 으로 와서 본문에서 iframe
    // 이 270px 만 차지해 손바닥 크기로 보인다. 720 이면 405x720 — 데스크톱
    // 본문 폭(720~1000)에 적당히 들어맞고 모바일은 buildEmbed 의 max-width:100%
    // 가 비율을 유지하며 자동 축소한다. 가로 16:9 영상은 854x480 으로 그대로
    // 응답 (480 < 720 이라 height 제약 안 걸림, maxwidth=854 가 width 를 cap).
    $endpoint = 'https://www.youtube.com/oembed?url=' . rawurlencode($url) . '&maxwidth=854&maxheight=720&format=json';
    $payload = RemoteFetcher::fetchJson($endpoint);
    if ($payload === null) {
      return null;
    }
    $info = [];
    if (isset($payload['width']) && is_numeric($payload['width'])) {
      $info['width'] = (int) $payload['width'];
    }
    if (isset($payload['height']) && is_numeric($payload['height'])) {
      $info['height'] = (int) $payload['height'];
    }
    if (!empty($payload['thumbnail_url']) && is_string($payload['thumbnail_url'])) {
      $info['thumbnail_url'] = $payload['thumbnail_url'];
    }
    return $info ?: null;
  }
}
