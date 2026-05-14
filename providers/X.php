<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;

/**
 * X (구 Twitter) 트윗 임베드.
 *
 * 공식 임베드 방식은 .twitter-tweet blockquote + platform.twitter.com/widgets.js
 * 조합. widgets.js 가 페이지 로드 후 .twitter-tweet 요소를 찾아 트윗 iframe 으로
 * 변환한다. X 리브랜드 이후에도 SDK 엔드포인트는 platform.twitter.com 그대로
 * 유지되며 (https://developer.twitter.com/en/docs/twitter-for-websites/embedded-tweets/overview)
 * twitter.com / x.com URL 둘 다 인식한다.
 *
 * widgets.js 본문 인라인은 HTMLPurifier 가 제거하므로 buildEmbed() 에서는
 * blockquote 만 출력하고, 글 보기 시점에 EventHandlers 가 getEmbedAssets()
 * 의 selector 를 검사해 SDK 를 head 로 주입한다. CKEditor ACF 가 .twitter-tweet
 * 클래스를 떨굴 가능성에 대비해 normalize 규칙으로 data-oembed-tweet-id 살아
 * 있는 노드에 클래스를 복원한다.
 */
class X extends Provider
{
  public string $name = 'X';
  public string $type = self::TYPE_SOCIAL;
  public bool $oembed = false;
  public array $hosts = [
    'twitter.com', 'www.twitter.com', 'mobile.twitter.com',
    'x.com', 'www.x.com', 'mobile.x.com',
  ];
  // 좁은 패턴(/i/web/status) 을 먼저 둬서 두번째 패턴이 'web' 을 username 으로
  // 잡아채지 않도록 한다 (Provider::match 는 first-match-wins).
  public array $patterns = [
    '~(?:https?:)?//(?:www\.|mobile\.)?(?:twitter|x)\.com/i/(?:web/)?status/(\d+)~i' => ['tweet_id'],
    '~(?:https?:)?//(?:www\.|mobile\.)?(?:twitter|x)\.com/(\w+)/status(?:es)?/(\d+)~i' => ['username', 'tweet_id'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $tweetId = $matchData['captures']['tweet_id'] ?? '';
    if ($tweetId === '') {
      return '';
    }
    $username = $matchData['captures']['username'] ?? '';

    // username 이 없을 때 '/i/web/status/' 를 그대로 쓰면 widgets.js 의
    // 퍼머링크 정규식(\w+/status(?:es)?/\d+)에 매칭되지 않아 blockquote 가
    // 트윗으로 변환되지 않는다 ('i' 뒤가 '/web' 이라 '/status' 와 어긋남).
    // '/i/status/' 형태로 출력하면 'i' 가 screen_name 자리를 채워 정규식이
    // 매칭되고, 이 URL 은 X 에서도 유효하게 동작한다.
    $tweetUrl = $username !== ''
      ? 'https://twitter.com/' . rawurlencode($username) . '/status/' . rawurlencode($tweetId)
      : 'https://twitter.com/i/status/' . rawurlencode($tweetId);
    $hrefHtml = htmlspecialchars($tweetUrl, ENT_QUOTES, 'UTF-8');
    $idHtml = htmlspecialchars($tweetId, ENT_QUOTES, 'UTF-8');

    return sprintf(
      '<blockquote class="twitter-tweet" data-oembed-tweet-id="%s" data-dnt="true">'
      . '<a href="%s" target="_blank" rel="noopener noreferrer">View on X</a>'
      . '</blockquote>',
      $idHtml,
      $hrefHtml
    );
  }

  public function getEmbedAssets(): array
  {
    return [
      [
        'selector' => '.twitter-tweet',
        'script' => 'https://platform.twitter.com/widgets.js',
        // widgets.js 는 platform.twitter.com 이 CORS 헤더를 보내지 않으므로
        // anonymous 모드를 강제하면 안 된다 (브라우저가 스크립트 로딩 차단).
        'normalize' => [
          ['detect' => 'blockquote[data-oembed-tweet-id]:not(.twitter-tweet)', 'addClass' => 'twitter-tweet'],
        ],
      ],
    ];
  }

  public function getEmbedHosts(): array
  {
    // widgets.js SDK 와 그것이 본문에 삽입하는 트윗 iframe 모두
    // platform.twitter.com 으로 호스팅된다 (twitter.com / x.com 은 입력
    // URL 매칭에만 쓰이고 화이트리스트에는 의미 없음).
    return ['platform.twitter.com'];
  }
}
