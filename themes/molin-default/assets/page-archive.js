/* 墨林 - Molinink · 作者 Kira · https://www.molinink.com */
/* 归档页折叠交互:事件委托到 document,一个页面渲染多份本模板也只绑一次。
   年、月各自开合互不干扰;工具栏两钮整体开合,文案在展开/收起间切换。不改 URL、不发请求。 */
(function () {
    'use strict';

    // 同一页面可能渲染多份本模板:先查后绑,避免重复委托
    var root = document.documentElement;
    if (root.getAttribute('data-archive-init') === '1') {
        return;
    }
    root.setAttribute('data-archive-init', '1');

    function each(nodes, fn) {
        Array.prototype.forEach.call(nodes, fn);
    }

    // 文案取 data-* 两种态,不在脚本里另存一份译文
    function setLabel(selector, open) {
        each(document.querySelectorAll(selector), function (btn) {
            var label = btn.getAttribute(open ? 'data-collapse' : 'data-expand');
            if (label) {
                btn.textContent = label;
            }
        });
    }

    function anyClosed(nodes) {
        return Array.prototype.some.call(nodes, function (node) {
            return !node.classList.contains('open');
        });
    }

    document.addEventListener('click', function (e) {
        var target = e.target;

        if (!target || !target.closest) {
            return;
        }

        // 月标题先拦:否则会连带触发外层年
        var head = target.closest('.month-head');
        if (head) {
            e.stopPropagation();
            head.parentElement.classList.toggle('open');
            return;
        }

        head = target.closest('.year-head');
        if (head) {
            head.parentElement.classList.toggle('open');
            return;
        }

        if (target.closest('[id="toggleAll"]')) {
            var openAll = anyClosed(document.querySelectorAll('.archive .year'));
            each(document.querySelectorAll('.archive .year'), function (year) {
                year.classList.toggle('open', openAll);
                each(year.querySelectorAll('.month'), function (month) {
                    month.classList.toggle('open', openAll);
                });
            });
            setLabel('[id="toggleAll"]', openAll);
            return;
        }

        if (target.closest('[id="toggleMonths"]')) {
            var openMonths = anyClosed(document.querySelectorAll('.archive .month'));
            each(document.querySelectorAll('.archive .month'), function (month) {
                month.classList.toggle('open', openMonths);
            });
            setLabel('[id="toggleMonths"]', openMonths);
        }
    });
})();
