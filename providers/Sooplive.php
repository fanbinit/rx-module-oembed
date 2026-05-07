<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;

/**
 * SOOP (구 아프리카TV) VOD / 캐치 / 라이브 임베드.
 *
 * 세 가지 입력 URL 형태를 지원한다:
 *   - https://vod.sooplive.com/player/{id}        → VOD 일반 (16:9)
 *   - https://vod.sooplive.com/player/{id}/catch  → 캐치 (세로형 클립, 5:4)
 *   - https://play.sooplive.com/{username}/{id}   → 라이브 (16:9)
 *
 * 공식 oEmbed 엔드포인트가 알려져 있지 않아 fetchInfo 는 override 하지 않는다
 * — 영상별 실제 비율은 받아올 수 없으므로 type 별 기본 비율로 폴백한다 (캐치
 * 만 5:4 로 별도 디폴트).
 */
class Sooplive extends Provider
{
  public string $name = 'SOOP';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['vod.sooplive.com', 'play.sooplive.com'];
  // catch 패턴을 일반 VOD 보다 먼저 둬서 narrower 매칭이 우선되도록 한다
  // (Provider::match 는 first-match-wins). play.sooplive.com 의 username
  // 경로는 영문/숫자/`.`/`_`/`-` 가 허용된다.
  public array $patterns = [
    '~^(?:https?:)?//vod\.sooplive\.com/player/(\d+)/catch/?(?:[?#].*)?$~i' => ['vod_id'],
    '~^(?:https?:)?//vod\.sooplive\.com/player/(\d+)/?(?:[?#].*)?$~i' => ['vod_id'],
    '~^(?:https?:)?//play\.sooplive\.com/([\w.\-]+)/(\d+)/?(?:[?#].*)?$~i' => ['username', 'live_id'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $url = $matchData['url'] ?? '';
    $captures = $matchData['captures'] ?? [];

    // 캐치 — 공식 임베드가 5:4 세로형, type=catch 쿼리와 채팅 OFF 가 디폴트.
    if (preg_match('~//vod\.sooplive\.com/player/\d+/catch~i', $url)) {
      $vodId = $captures['vod_id'] ?? '';
      if ($vodId === '') {
        return '';
      }
      $w = $width ?: 640;
      $h = $height ?: 512;
      $src = 'https://vod.sooplive.com/player/' . rawurlencode($vodId)
        . '/embed?type=catch&showChat=false&autoPlay=true&mutePlay=true';
      $allow = 'clipboard-write; web-share';
    } elseif (!empty($captures['vod_id'])) {
      // 일반 VOD — 16:9, 채팅 ON 디폴트.
      [$w, $h] = $this->getDimensions($width, $height);
      $src = 'https://vod.sooplive.com/player/' . rawurlencode($captures['vod_id'])
        . '/embed?showChat=true&autoPlay=true&mutePlay=true';
      $allow = 'clipboard-write; web-share';
    } elseif (!empty($captures['username']) && !empty($captures['live_id'])) {
      // 라이브 — 16:9. allow=loopback-network 는 라이브 임베드 공식 스니펫 표기.
      [$w, $h] = $this->getDimensions($width, $height);
      $src = 'https://play.sooplive.com/' . rawurlencode($captures['username'])
        . '/' . rawurlencode($captures['live_id']) . '/embed';
      $allow = 'loopback-network';
    } else {
      return '';
    }

    $ratio = $h > 0 ? (string) round($w / $h, 4) : '1.7778';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" allow="%s" allowfullscreen loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio,
      $allow
    );
  }

  public function getEmbedHosts(): array
  {
    // buildEmbed 가 출력하는 iframe 의 src 호스트만 화이트리스트에 필요.
    return ['vod.sooplive.com', 'play.sooplive.com'];
  }
}
