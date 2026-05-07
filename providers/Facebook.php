<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;
use Rhymix\Modules\Oembed\Models\RemoteFetcher;

/**
 * Facebook 게시물/비디오/Watch/릴 임베드.
 *
 * Meta(Graph) oEmbed 는 페이지 액세스 토큰이 필요하므로 사용하지 않는다.
 * 게시물/비디오는 공식 connect.facebook.net SDK + .fb-post / .fb-video XFBML
 * 마크업으로 클라이언트에서 자동 변환한다. 릴(/reel/<id>)은 SDK XFBML 의
 * data-href 가 안정적으로 렌더하지 않는 사례가 있어 공식 Embed 버튼이 만들
 * 어 주는 /plugins/video.php iframe 형식으로 직접 출력한다.
 *
 * SDK 스크립트는 본문에 함께 저장하면 HTMLPurifier 가 제거하므로 buildEmbed()
 * 에서는 div / iframe 만 출력하고, 글 보기 시점에 EventHandlers 가
 * getEmbedAssets() 의 marker 를 검사해 head 로 주입한다.
 */
class Facebook extends Provider
{
  public string $name = 'Facebook';
  public string $type = self::TYPE_SOCIAL;
  public bool $oembed = false;
  public array $hosts = ['www.facebook.com', 'facebook.com', 'm.facebook.com', 'fb.watch'];
  public array $patterns = [
    '~(?:https?:)?//(?:www\.|m\.)?facebook\.com/(?:[\w.\-]+)/posts/[\w-]+~i' => [],
    '~(?:https?:)?//(?:www\.|m\.)?facebook\.com/permalink\.php\?[^"\s]*story_fbid=[\w-]+~i' => [],
    '~(?:https?:)?//(?:www\.|m\.)?facebook\.com/(?:[\w.\-]+)/videos/[\w-]+~i' => [],
    '~(?:https?:)?//(?:www\.|m\.)?facebook\.com/watch/?\?v=[\w-]+~i' => [],
    '~(?:https?:)?//fb\.watch/[\w-]+/?~i' => [],
    '~(?:https?:)?//(?:www\.|m\.)?facebook\.com/photo/?\?[^"\s]*fbid=[\w-]+~i' => [],
    // 릴(reel) — 공식 permalink 형식. /share/r/<code>/ 를 redirect 로 풀면 이 형식
    // 으로 도착하므로 같은 패턴이 직접 paste 케이스와 share resolve 케이스를 함께 잡는다.
    '~(?:https?:)?//(?:www\.|m\.)?facebook\.com/reel/\d+~i' => [],
    // 공유 단축 URL — match() 가 redirect 를 따라가 canonical permalink 로 정규화한 뒤
    // 다시 매칭한다. SDK / plugin iframe 의 data-href 는 share 링크를 자동으로 풀지 않는다.
    '~(?:https?:)?//(?:www\.|m\.)?facebook\.com/share/[a-z]/[\w-]+/?~i' => [],
  ];

  /**
   * /share/[type]/<code>/ 단축 URL 은 SDK / plugin iframe 의 data-href 가
   * 자동으로 풀지 못하므로 paste 시점에 한 번만 redirect 를 따라 canonical
   * permalink 로 정규화한 뒤 다시 매칭해 정확한 임베드 분기(릴/포스트/비디오)
   * 를 고른다. resolve 가 실패하면 share 매칭 결과를 그대로 둬서 buildEmbed
   * 가 share URL 을 인지하고 fail 폴백을 타도록 한다 (controller 가 OG 카드
   * 흐름으로 떨어져 폴백을 시도).
   */
  public function match(string $url): ?array
  {
    $matched = parent::match($url);
    if ($matched === null) {
      return null;
    }
    if (!preg_match('~^https?://(?:www\.|m\.)?facebook\.com/share/[a-z]/[\w-]+/?~i', $matched['url'])) {
      return $matched;
    }
    $resolved = $this->resolveShareUrl($matched['url']);
    if ($resolved === null || $resolved === $matched['url']) {
      return $matched;
    }
    // canonical 이 알려진 permalink (reel/posts/videos/...) 면 그 매칭 결과를 사용,
    // 아니면 원래 share 매칭 결과의 url 만 canonical 로 갈아끼워 buildEmbed 가
    // 받게 한다.
    $rematched = parent::match($resolved);
    return $rematched ?? array_merge($matched, ['url' => $resolved]);
  }

  /**
   * 일반 포스트는 social, 릴(/reel/<id>)은 multimedia 로 분류한다. 릴은 세로형
   * 비디오 콘텐츠라 wrapper 가 multimedia 분기 (라운드된 iframe, social 라벨
   * 박스 미적용) 를 받는 게 시각적으로 자연스럽다. /share/r/ 가 아직 canonical
   * 로 정규화되지 않은 상태라면 share URL 자체를 보고 판단 — match() 가 share
   * URL 을 redirect 로 풀어 /reel/ 로 바꿔 두는 흐름이 일반적이지만, resolve
   * 가 transient 로 실패한 케이스에도 한 번 더 분기를 잡기 위함.
   */
  public function resolveType(array $matchData): string
  {
    $url = $matchData['url'] ?? '';
    if (preg_match('~/reel/\d+|/share/r/[\w-]+~i', $url)) {
      return self::TYPE_MULTIMEDIA;
    }
    return $this->type;
  }

  private function resolveShareUrl(string $shareUrl): ?string
  {
    $fetched = RemoteFetcher::fetchHtml($shareUrl);
    if ($fetched === null) {
      return null;
    }
    $finalUrl = $fetched['final_url'] ?? '';
    if ($finalUrl === '' || $finalUrl === $shareUrl) {
      return null;
    }
    // ?rdid=...&share_url=... 같은 추적 쿼리 제거. SDK / plugin iframe 가 받는
    // data-href 는 깔끔한 permalink 일 때 가장 안정적이다.
    $stripped = strtok($finalUrl, '?');
    return is_string($stripped) && $stripped !== '' ? $stripped : $finalUrl;
  }

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $url = $matchData['url'] ?? '';
    if ($url === '') {
      return '';
    }
    // share URL 이 canonical 로 정규화되지 않은 채 도착했다는 건 redirect resolve
    // 가 실패했다는 뜻. SDK 가 share 단축 URL 을 직접 렌더하지 못하므로 빈 임베드
    // 마크업을 만드느니 fail 을 반환해 controller 가 OG 카드 흐름으로 폴백한다.
    if (preg_match('~/share/[a-z]/[\w-]+/?~i', $url)) {
      return '';
    }
    if (preg_match('~/reel/\d+~i', $url)) {
      return $this->buildReelEmbed($url, $width, $height);
    }
    $isVideo = preg_match('~/videos/|/watch/?\?v=|fb\.watch/~i', $url) === 1;
    $cssClass = $isVideo ? 'fb-video' : 'fb-post';
    $hrefHtml = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    [$resolvedWidth] = $this->getDimensions($width, $height);

    return sprintf(
      '<div class="%s" data-href="%s" data-width="%d"></div>',
      $cssClass,
      $hrefHtml,
      $resolvedWidth
    );
  }

  /**
   * 릴은 /plugins/video.php iframe 으로 직접 임베드한다. SDK XFBML 의 .fb-video
   * 가 reel permalink 를 안정적으로 렌더하지 않는 케이스가 있어, Facebook 의
   * 공식 Embed 버튼이 출력하는 iframe 형식과 동일하게 9:16 세로 비율로 만든다.
   * width/height 미지정 시 360x640 기본.
   */
  private function buildReelEmbed(string $url, ?int $width, ?int $height): string
  {
    if ($width && $height) {
      $w = $width;
      $h = $height;
    } elseif ($width) {
      $w = $width;
      $h = (int) round($width * 16 / 9);
    } elseif ($height) {
      $w = (int) round($height * 9 / 16);
      $h = $height;
    } else {
      $w = 360;
      $h = 640;
    }
    $src = 'https://www.facebook.com/plugins/video.php?height=' . $h
      . '&href=' . rawurlencode($url)
      . '&show_text=false&width=' . $w . '&t=0';
    // aspect-ratio 0.5625 는 9/16 의 단일 number 표현. Rhymix HTMLFilter 가
    // 단일 number 형식을 통과시키므로 sanitizer 에 살아남고, max-width:100% 와
    // 결합해 모바일 뷰에서 비율을 유지하며 자동 축소된다.
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:0.5625;height:auto;border:none;overflow:hidden" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h
    );
  }

  public function getEmbedAssets(): array
  {
    return [
      [
        'selector' => '.fb-post, .fb-video',
        'script' => 'https://connect.facebook.net/ko_KR/sdk.js#xfbml=1&version=v18.0',
        'crossorigin' => true,
      ],
    ];
  }

  public function getEmbedHosts(): array
  {
    // SDK 가 변환한 iframe 과 릴 직접 iframe 모두 www.facebook.com/plugins/* 로
    // 호스팅된다. SDK 스크립트 로드를 위해 connect.facebook.net 도 필요.
    return ['www.facebook.com', 'connect.facebook.net'];
  }
}
