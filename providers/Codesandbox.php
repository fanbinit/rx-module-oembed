<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;
use Rhymix\Modules\Oembed\Models\RemoteFetcher;

/**
 * CodeSandbox 샌드박스 임베드.
 *
 * 지원 URL 형태:
 *   codesandbox.io/s/{id}          — 공유 링크
 *   codesandbox.io/embed/{id}      — 임베드 링크
 *   codesandbox.io/p/sandbox/{id}  — 프로젝트 링크
 *   {6chars}.csb.app               — CSB 단축 도메인
 *
 * 공식 oEmbed (https://codesandbox.io/oembed?url=…) 로 섬네일을 가져온다.
 */
class Codesandbox extends Provider
{
  public string $name = 'CodeSandbox';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['codesandbox.io', 'www.codesandbox.io', 'csb.app'];
  // 좁은 패턴(named path) 을 먼저, csb.app 단축 도메인을 나중에.
  public array $patterns = [
    '~(?:https?:)?//(?:www\.)?codesandbox\.io/(?:s|embed|p/sandbox)/([^?\s]+)~i' => ['sandbox_id'],
    '~(?:https?:)?//(\w{6})\.csb\.app~i' => ['sandbox_id'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $sandboxId = $matchData['captures']['sandbox_id'] ?? '';
    if ($sandboxId === '') {
      return '';
    }
    [$w, $h] = $this->getDimensions($width, $height);
    $src = 'https://codesandbox.io/embed/' . $sandboxId . '?autoresize=1&fontsize=14&hidenavigation=1&theme=dark';
    $ratio = $h > 0 ? (string) round($w / $h, 4) : '1.7778';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" loading="lazy" sandbox="allow-forms allow-modals allow-popups allow-presentation allow-same-origin allow-scripts"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function fetchInfo(string $url): ?array
  {
    // 공식 oEmbed — 섬네일 URL 을 얻어 본문 첨부로 등록.
    // sandbox ID 를 추출해 s/ 형태로 정규화한 URL 을 oEmbed 에 전달.
    if (!preg_match('~(?:https?:)?//(?:(?:www\.)?codesandbox\.io/(?:s|embed|p/sandbox)/([^?\s]+)|(\w{6})\.csb\.app)~i', $url, $m)) {
      return null;
    }
    $sandboxId = !empty($m[1]) ? $m[1] : ($m[2] ?? '');
    if ($sandboxId === '') {
      return null;
    }
    $oembedUrl = 'https://codesandbox.io/oembed?url=' . rawurlencode('https://codesandbox.io/s/' . $sandboxId);
    $payload = RemoteFetcher::fetchJson($oembedUrl);
    if ($payload === null) {
      return null;
    }
    $info = [];
    if (!empty($payload['thumbnail_url']) && is_string($payload['thumbnail_url'])) {
      $info['thumbnail_url'] = $payload['thumbnail_url'];
    }
    return $info ?: null;
  }

  public function getEmbedHosts(): array
  {
    return ['codesandbox.io'];
  }
}
