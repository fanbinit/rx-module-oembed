<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;

/**
 * CodePen 펜 임베드.
 *
 * 공식 임베드 URL 형태:
 *   https://codepen.io/{username}/embed/{pen_id}?default-tab=html,result
 *
 * CodePen 공개 API 가 없으므로 섬네일은 가져오지 않는다.
 * (shots.codepen.io 는 로그인 인증이 필요하므로 서버측 조회 불가.)
 */
class Codepen extends Provider
{
  public string $name = 'CodePen';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['codepen.io', 'www.codepen.io'];
  public array $patterns = [
    '~(?:https?:)?//(?:www\.)?codepen\.io/([a-zA-Z][a-zA-Z0-9_-]*)/(?:pen|details)/([a-zA-Z0-9]+)/?~i' => ['username', 'pen_id'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $username = $matchData['captures']['username'] ?? '';
    $penId = $matchData['captures']['pen_id'] ?? '';
    if ($username === '' || $penId === '') {
      return '';
    }
    [$w, $h] = $this->getDimensions($width, $height);
    $src = 'https://codepen.io/' . rawurlencode($username) . '/embed/' . rawurlencode($penId) . '?default-tab=html%2Cresult';
    $ratio = $h > 0 ? (string) round($w / $h, 4) : '1.3333';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" scrolling="no" frameborder="no" allowtransparency allowfullscreen loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function getEmbedHosts(): array
  {
    return ['codepen.io'];
  }
}
