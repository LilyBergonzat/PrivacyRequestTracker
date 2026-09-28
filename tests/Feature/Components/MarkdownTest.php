<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

function renderMarkdown(?string $content): string
{
    return Blade::render('<x-markdown :content="$content" />', ['content' => $content]);
}

describe('rendering', function () {
    test('markdown is rendered as html', function () {
        $html = renderMarkdown("**bold** and *italic*\n\n- one\n- two");

        expect($html)->toContain('<strong>bold</strong>')
            ->toContain('<em>italic</em>')
            ->toContain('<li>one</li>');
    });

    test('code blocks are rendered and their content is escaped', function () {
        $html = renderMarkdown("```js\ndb.notes.deleteMany({ recipient: uid });\nif (a < b) {}\n```");

        expect($html)->toContain('<pre><code class="language-js">')
            ->toContain('db.notes.deleteMany({ recipient: uid });')
            ->toContain('a &lt; b');
    });

    test('placeholders are left untouched for the client-side substitution', function () {
        $html = renderMarkdown('const uid = "{{discord_id}}";');

        expect($html)->toContain('{{discord_id}}');
    });

    test('the content is wrapped in the prose styles', function () {
        expect(renderMarkdown('hello'))->toContain('prose');
    });

    test('empty content renders without errors', function (?string $content) {
        expect(renderMarkdown($content))->toContain('prose');
    })->with([
        'null' => null,
        'empty string' => '',
    ]);
});

describe('security', function () {
    test('raw html is escaped instead of executed', function (string $payload) {
        $html = renderMarkdown("Connect to the DB\n\n" . $payload);

        expect($html)->not->toContain($payload)
            ->not->toMatch('/<(script|iframe|img|svg)\b/i');
    })->with([
        'script tag' => '<script>alert(1)</script>',
        'inline script tag' => 'text <script>alert(1)</script> text',
        'image with onerror' => '<img src=x onerror=alert(1)>',
        'iframe' => '<iframe src="https://evil.example"></iframe>',
        'svg with onload' => '<svg onload=alert(1)></svg>',
    ]);

    test('the escaped html is still visible as text', function () {
        expect(renderMarkdown('<script>alert(1)</script>'))->toContain('&lt;script&gt;');
    });

    test('dangerous links lose their href', function (string $url) {
        $html = renderMarkdown("[click me]({$url})");

        expect($html)->toContain('click me')
            ->not->toContain($url);
    })->with([
        'javascript' => 'javascript:alert(1)',
        'vbscript' => 'vbscript:msgbox(1)',
        'data' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    ]);

    test('normal links are kept', function () {
        $html = renderMarkdown('[Modmail docs](https://github.com/modmail-dev/modmail)');

        expect($html)->toContain('href="https://github.com/modmail-dev/modmail"');
    });
});
