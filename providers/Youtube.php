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
    // Shorts(/shorts/<id>)는 세로 9:16 영상이지만 YouTube oEmbed 는 Shorts URL
    // 에도 가로 16:9(854x480)를 응답한다 — fetchInfo 로는 세로 비율을 알 수
    // 없으므로, /shorts/ 로 paste 된 케이스는 oEmbed 가 채운 width/height 을
    // 무시하고 9:16 을 강제한다 (Facebook 릴 처리와 동일한 방식). watch?v= /
    // youtu.be 로 paste 된 Shorts 는 URL 만으로 구분되지 않는 한계가 있다.
    if (preg_match('~youtube\.com/shorts/~i', $matchData['url'] ?? '')) {
      [$w, $h] = [405, 720];
    } else {
      [$w, $h] = $this->getDimensions($width, $height);
    }
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
    // 공식 oEmbed endpoint — 키 발급 불필요. 두 가지 용도로 쓴다:
    //   - thumbnail_url → ImageAttacher 로 본문 첨부 등록 (게시판 자동 섬네일 등).
    //   - width/height → 가로 영상 iframe 의 기본 dimension.
    // 주의: YouTube oEmbed 는 Shorts URL 에도 가로 16:9(854x480)를 응답하고 세로
    // 9:16 은 알려주지 않는다. 따라서 Shorts 비율 처리는 buildEmbed 가 URL 패턴
    // 으로 직접 하며, 여기서 받는 width/height 은 가로 영상 한정으로만 의미가 있다.
    // maxwidth 와 maxheight 는 *둘 다* 지정해야 한도가 적용된다 — 한쪽만 주거나
    // 아예 없으면 200x113 / 356x200 같은 작은 기본값으로 응답한다. 854x480 (16:9
    // 480p 표준) 으로 받아 일반적인 본문 폭(720~1000) 안에 fit 시킨다.
    $endpoint = 'https://www.youtube.com/oembed?url=' . rawurlencode($url) . '&maxwidth=854&maxheight=480&format=json';
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
