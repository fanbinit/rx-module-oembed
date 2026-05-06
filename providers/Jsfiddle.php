<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;

/**
 * JSFiddle 코드 스니펫 임베드.
 *
 * 공식 임베드 URL 형태:
 *   //jsfiddle.net/{username}/{fiddle_id}/{revision}/embedded/result,html,css,js/
 * revision 이 없는 경우 최신 버전이 표시된다.
 *
 * username 이 예약어('user', 'boilerplate') 인 경우나 fiddle_id 가 없으면
 * 유효하지 않은 URL 로 처리해 '' 반환.
 */
class Jsfiddle extends Provider
{
  public string $name = 'JSFiddle';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['jsfiddle.net', 'www.jsfiddle.net'];
  public array $patterns = [
    '~(?:https?:)?//(?:www\.)?jsfiddle\.net/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)(?:/([A-Za-z0-9_-]+))?~i' => ['username', 'fiddle_id', 'revision'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $username = $matchData['captures']['username'] ?? '';
    $fiddleId = $matchData['captures']['fiddle_id'] ?? '';
    // 예약어 username 이거나 fiddle_id 가 없으면 OG 카드 폴백.
    if ($username === '' || in_array($username, ['user', 'boilerplate'], true) || $fiddleId === '') {
      return '';
    }
    $revision = $matchData['captures']['revision'] ?? '';
    [$w, $h] = $this->getDimensions($width, $height);

    $path = '/' . rawurlencode($username) . '/' . rawurlencode($fiddleId) . '/';
    if ($revision !== '') {
      $path .= rawurlencode($revision) . '/';
    }
    $src = 'https://jsfiddle.net' . $path . 'embedded/result,html,css,js/';
    $ratio = $h > 0 ? (string) round($w / $h, 4) : '1.3333';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" allowfullscreen allowtransparency loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function getEmbedHosts(): array
  {
    return ['jsfiddle.net'];
  }
}
