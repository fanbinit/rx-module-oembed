# rx-module-oembed

Rhymix 용 oEmbed 모듈. CKEditor 4 에서 사용자가 URL 을 붙여넣으면 자동으로
**임베드(iframe/blockquote/oEmbed)** 또는 **Open Graph 미리보기 카드** 로 변환합니다.
기존 `preview` 모듈을 대체할 수 있도록 설계되었으며, preview 시절 게시물에 저장된
카드(`preview_card_*`) / 임베드(`media_embed_wrapper`) 마크업을 호환 모드에서
그대로 출력해 마이그레이션 후에도 게시물이 깨지지 않도록 합니다.

## 주요 특징

- **PHP 파일 한 개로 새 서비스 추가** — `providers/{Name}.php` 파일 하나만 추가하면
  소스 변경이나 빌드 없이 즉시 등록됩니다.
- **기본 Provider 4종** — YouTube, Facebook, Instagram, X (Meta 토큰 불필요).
- **Open Graph 카드 자동 생성** — 매칭되는 provider 가 없는 URL 은 OG/Twitter Card
  메타를 추출해 미리보기 카드로 변환합니다.
- **SSRF 가드** — 사설/예약 IP, localhost, 클라우드 메타데이터 엔드포인트
  (169.254.169.254) 차단. 리다이렉트도 검증.
- **iframe whitelist 명시적 승인** — 사이트 보안 정책상 oembed 가 시스템
  설정을 자동 갱신하지 않습니다. 어드민 → oEmbed → Provider 관리 화면에서
  승인이 필요한 호스트 목록을 보여주고, 운영자가 시스템 → 설정 → 보안 →
  외부 멀티미디어 허용에서 직접 등록합니다. 등록 전까지 본문 출력 시
  iframe 이 차단됩니다.
- **preview 게시물 호환** — 호환 모드 ON 시 preview 시절에 저장된
  `preview_card_*` / `media_embed_wrapper` 마크업을 게시물 읽기 화면에서
  같은 모양으로 출력하고, 인스타그램·페이스북·X 임베드의 SDK 도 자동
  로드합니다. 새 사이트라면 꺼두어도 됩니다.

## 설치

Rhymix 사이트의 `modules/oembed` 위치에 이 저장소를 배치합니다.

```sh
cd /path/to/rhymix-site/modules
git clone <이 저장소 URL> oembed
```

설치 후 관리자 화면 → 시스템 → 모듈 관리에서 oEmbed 모듈을 활성화합니다.

> ⚠️ `preview` 모듈과 동시 활성화 금지. 어드민 화면이 활성 충돌을 감지해
> 경고와 마이그레이션 가이드를 표시합니다.

## 새 Provider 추가

`modules/oembed/providers/` 디렉터리에 PHP 파일 하나만 추가하면 됩니다.

```php
// modules/oembed/providers/Vimeo.php
<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;

class Vimeo extends Provider
{
  public string $name = 'Vimeo';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['vimeo.com', 'player.vimeo.com'];
  public array $patterns = [
    '#(?:https?:)?//(?:www\.|player\.)?vimeo\.com/(\d+)#i' => ['video_id'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $videoId = $matchData['captures']['video_id'] ?? '';
    if ($videoId === '') return '';
    [$w, $h] = $this->getDimensions($width, $height);
    $src = 'https://player.vimeo.com/video/' . rawurlencode($videoId);
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'), $w, $h
    );
  }
}
```

저장 후 어드민 → oEmbed → Provider 관리 → **Provider 캐시 새로고침** 버튼을
한 번 누르면 즉시 등록됩니다(또는 Rhymix 캐시 재컴파일).

### Provider 인터페이스

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| `$name` | `string` | 어드민에 표시할 이름 |
| `$type` | `'multimedia' \| 'social'` | 미지정 크기 시 16:9 / 4:3 비율 결정 |
| `$oembed` | `bool` | true 면 oEmbed 엔드포인트 호출 (현재 v0.x 에서는 직접 임베드만) |
| `$hosts` | `string[]` | iframe whitelist 자동 등록 대상 |
| `$patterns` | `array<string, string[]>` | PCRE 패턴 → 캡처 그룹 이름 |

`match()` 와 `getDimensions()` 는 베이스 클래스가 제공하므로 일반적으로
`buildEmbed()` 만 구현하면 됩니다.

### Claude Code를 이용한 Provider 추가

Claude Code 및 기타 AI 코딩 어시스턴트를 이용하여 다음과 같이 Provider 추가가 가능합니다. Claude Code를 이용하지 않는 경우 별도로 CLAUDE.md를 읽을 수 있도록 처리해주시기 바랍니다.

예제 프롬프트 :

```

새로운 branch를 만들고, sooplive.co.kr 서비스의 Provider를 추가해줘

해당 서비스의 주소 및 임베드 패턴은 다음과 같아

| 주소 | 임베드 스크립트 |
| --- | --- |
| https://vod.sooplive.com/player/194919403 | <iframe id="soop_player_video" width="640" height="360" src="https://vod.sooplive.com/player/194919403/embed?showChat=true&autoPlay=true&mutePlay=true" frameborder="0" allowfullscreen="true" allow="clipboard-write; web-share;"></iframe> |
| https://vod.sooplive.com/player/192089437/catch | <iframe id="soop_player_video" width="640" height="512" src="https://vod.sooplive.com/player/192089437/embed?type=catch&showChat=false&autoPlay=true&mutePlay=true" frameborder="0" allowfullscreen="true" allow="clipboard-write; web-share;"></iframe> |
| https://play.sooplive.com/phonics1/293820033 | <iframe src="https://play.sooplive.com/phonics1/293820033/embed" width="640" height="360" frameborder="0" allowfullscreen allow="loopback-network"></iframe> |

```

## 다른 에디터 통합

CKEditor 4 외 에디터(예: Draft.js, Quill, TinyMCE 등)에서도
`procOembedFetch` 액션을 직접 호출해 임베드/카드 마크업을 받아 삽입할 수
있습니다. 자세한 흐름과 예시 코드는
[`docs/editor-integration.md`](docs/editor-integration.md) 를 참고하세요.

## 디렉터리 구조

PSR-4 (`Rhymix\Modules\Oembed\*`).

```
modules/oembed/
├── conf/                      info.xml, module.xml
├── controllers/               Base / View / Controller / Admin / Install / EventHandlers
├── models/                    Config / Provider / Registry / RemoteFetcher /
│                              OpenGraph / CardRenderer / ImageAttacher
├── providers/                 Youtube / Facebook / Instagram / X
├── views/admin/               config.blade.php, providers.blade.php
├── tpl/js/                    _ckeditor.js
├── tpl/css/                   style.css
└── lang/                      ko.php, en.php
```

## 컨트리뷰션

이슈와 PR 을 환영합니다. PR 시:

1. PHP 8.4 기준으로 작성하세요.
2. 새 provider 는 한 파일에 한 클래스, 파일명 = 클래스명 규칙을 지키세요.
3. 외부 호출이 있는 코드는 `RemoteFetcher` 를 통해서만 하세요(SSRF 가드 통과).
4. 사용자 입력은 항상 `htmlspecialchars(ENT_QUOTES, 'UTF-8')` 로 escape 하세요.

## 라이선스

MIT — [LICENSE](LICENSE) 참고.
