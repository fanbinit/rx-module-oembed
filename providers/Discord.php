<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;
use Rhymix\Modules\Oembed\Models\RemoteFetcher;

/**
 * Discord 서버 위젯 임베드.
 *
 * 지원 URL 형태:
 *   discord.com/channels/{guild_id}/...   — 채널 URL (guild_id 직접 추출)
 *   discord.com/invite/{code}             — 초대 링크 (API 로 guild_id 해석)
 *   discord.gg/{code}                     — 단축 초대 링크 (API 로 guild_id 해석)
 *
 * 초대 링크는 Discord 공개 API (discord.com/api/v10/invites/{code}) 로
 * guild_id 를 조회한다. API 실패 시 '' 반환 → OG 카드 폴백.
 *
 * 주의: Discord 서버 위젯은 해당 서버에서 서버 위젯 기능을 활성화한 경우에만
 * 표시된다. 비활성화 서버는 임베드가 표시되지 않을 수 있다.
 */
class Discord extends Provider
{
  public string $name = 'Discord';
  public string $type = self::TYPE_SOCIAL;
  public bool $oembed = false;
  public array $hosts = [
    'discord.com', 'www.discord.com',
    'discord.gg', 'www.discord.gg',
  ];
  // 좁은 패턴(channels — guild_id 직접 포함)을 먼저, 초대 링크를 나중에.
  public array $patterns = [
    '~(?:https?:)?//(?:www\.)?discord\.com/channels/(\d+)(?:/\d+)*(?:[?#].+)?~i' => ['guild_id'],
    '~(?:https?:)?//(?:www\.)?discord\.com/invite/(\w+)(?:[?#].+)?~i' => ['invite_code'],
    '~(?:https?:)?//(?:www\.)?discord\.gg/(\w+)(?:[?#].+)?~i' => ['invite_code'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $guildId = $matchData['captures']['guild_id'] ?? '';
    $inviteCode = $matchData['captures']['invite_code'] ?? '';

    // 초대 코드인 경우 공개 API 로 guild_id 해석.
    if ($guildId === '' && $inviteCode !== '') {
      $apiUrl = 'https://discord.com/api/v10/invites/' . rawurlencode($inviteCode);
      $payload = RemoteFetcher::fetchJson($apiUrl);
      $guildId = (string) ($payload['guild']['id'] ?? '');
    }

    // guild_id 가 숫자가 아니거나 비어 있으면 OG 카드 폴백.
    if ($guildId === '' || !ctype_digit($guildId)) {
      return '';
    }

    [$w, $h] = $this->getDimensions($width, $height);
    $src = 'https://discordapp.com/widget?id=' . rawurlencode($guildId) . '&theme=dark';
    $ratio = $h > 0 ? (string) round($w / $h, 4) : '0.75';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" allowtransparency loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function getEmbedHosts(): array
  {
    return ['discordapp.com'];
  }
}
