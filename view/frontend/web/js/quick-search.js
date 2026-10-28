/**
 * WarpSearch quick search. Augments Magento's #search input with a dropdown fed by /warpsearch/search/suggest
 * (a server-side proxy: the browser never sees the WarpSearch key). If the endpoint fails or answers ok:false the
 * dropdown stays closed and Magento's native search keeps working. Enter still submits the native results page.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, input) {
        var $input = $(input),
            $list = $('<ul class="ws-qs" role="listbox" hidden></ul>'),
            timer = null,
            seq = 0,
            active = -1;

        var $form = $input.closest('form');

        $form.css('position', 'relative').append($list);
        $('body').addClass('ws-qs-active'); // hides Magento's own suggestion dropdown to avoid two lists

        function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

        function close() { $list.attr('hidden', true).empty(); active = -1; }

        function render(items) {
            if (!items.length) { return close(); }
            $list.html(items.map(function (p) {
                return '<li role="option"><a href="' + esc(p.url) + '">' +
                    (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') +
                    '<span class="ws-name">' + esc(p.name) + '</span>' +
                    '<span class="ws-price">' + esc(p.price) + '</span></a></li>';
            }).join('')).removeAttr('hidden');
            active = -1;
        }

        function query() {
            var q = $.trim($input.val()), mine = ++seq;
            if (q.length < config.minChars) { return close(); }
            $.getJSON(config.suggestUrl, { q: q }).done(function (res) {
                if (mine !== seq) { return; } // a newer keystroke superseded this response
                if (res && res.ok) { $('body').addClass('ws-qs-active'); render(res.items || []); } else { close(); $('body').removeClass('ws-qs-active'); }
            }).fail(function () { close(); $('body').removeClass('ws-qs-active'); });
        }

        $input.on('input.wsqs', function () {
            clearTimeout(timer);
            timer = setTimeout(query, config.debounce);
        }).on('keydown.wsqs', function (e) {
            var $items = $list.children();
            if (e.key === 'Escape') { return close(); }
            if (!$items.length || $list.attr('hidden')) { return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                active = (active + (e.key === 'ArrowDown' ? 1 : -1) + $items.length) % $items.length;
                $items.removeClass('ws-active').eq(active).addClass('ws-active');
            } else if (e.key === 'Enter' && active >= 0) {
                e.preventDefault();
                window.location.href = $items.eq(active).find('a').attr('href');
            }
        });

        // Magento's own search widget (form-mini) submits the form on Enter from its keydown handler, which runs
        // before ours, so a highlighted suggestion must also win over the form submit
        $form.on('submit.wsqs', function (e) {
            if (active >= 0 && !$list.attr('hidden')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                window.location.href = $list.children().eq(active).find('a').attr('href');
            }
        });

        $(document).on('click.wsqs', function (e) {
            if (!$(e.target).closest($list).length && e.target !== input) { close(); }
        });
    };
});
