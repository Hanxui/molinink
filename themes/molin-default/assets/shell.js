/* 墨林 - Molinink · 作者 Kira · https://www.molinink.com */
/* 移动端页头:两条杠 ↔ 液态模糊浮层导航。浮层只是呈现容器,导航数据不变。
   开着时锁背景滚动、Esc 可关、焦点移进移出并按 Tab 在浮层内循环(键盘与读屏用户可达)。 */
(function () {
    'use strict';

    var btn = document.getElementById('menu-btn');
    var overlay = document.getElementById('menu-overlay');
    var close = document.getElementById('menu-close');

    if (!btn || !overlay || !close) {
        return;
    }

    var lastFocus = null;
    var prevOverflow = null;

    var focusables = function () {
        return Array.prototype.slice.call(overlay.querySelectorAll('a[href], button'));
    };

    var isOpen = function () {
        return overlay.classList.contains('open');
    };

    // 背景滚动锁要同时按住 html 与 body:文档滚动条挂在 html 上,只锁 body 挡不住滚轮
    var lockScroll = function (on) {
        var root = document.documentElement;

        if (on) {
            prevOverflow = { html: root.style.overflow, body: document.body.style.overflow };
            root.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
        } else if (prevOverflow !== null) {
            root.style.overflow = prevOverflow.html;
            document.body.style.overflow = prevOverflow.body;
            prevOverflow = null;
        }
    };

    var toggle = function (open) {
        overlay.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        lockScroll(open);

        if (open) {
            lastFocus = document.activeElement;
            // 等下一帧再聚焦:浮层的 visibility 还没从 hidden 翻过来时,focus() 会被浏览器吞掉
            window.requestAnimationFrame(function () {
                var first = focusables()[0];
                if (first) {
                    first.focus();
                }
            });
        } else {
            var back = lastFocus;
            lastFocus = null;
            // 开浮层时焦点多半还在 body(点按钮不夺焦),那就还给汉堡按钮
            var target = back && back !== document.body && typeof back.focus === 'function' ? back : btn;
            target.focus();
        }
    };

    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-hidden', 'true');

    btn.addEventListener('click', function () { toggle(true); });
    close.addEventListener('click', function () { toggle(false); });

    document.addEventListener('keydown', function (e) {
        if (!isOpen()) {
            return;
        }

        if (e.key === 'Escape' || e.key === 'Esc') {
            e.preventDefault();
            toggle(false);

            return;
        }

        if (e.key !== 'Tab') {
            return;
        }

        // Tab 在浮层内循环:首尾互相兜,不跑回被盖住的页面
        var items = focusables();

        if (items.length === 0) {
            return;
        }

        var first = items[0];
        var last = items[items.length - 1];

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });
})();

/* 正文图片灯箱(#56):点图从原位放大到视口中央,关闭原路缩回(「从哪来回哪去」)。
   图片文件不动,只是呈现;正文里带链接的图不抢跳转。 */
(function () {
    'use strict';

    var content = document.querySelector('.body');
    if (!content) {
        return;
    }

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var OPEN = reduce ? 0 : 300;
    var CLOSE = reduce ? 0 : 260;
    var EASE = 'cubic-bezier(.22,.61,.36,1)';

    var lb = null;
    var lbImg = null;
    var srcImg = null;
    var closing = false;

    // 大图的目标位:装进 92vw × 92vh,不放大过原寸
    function target(src) {
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var s = Math.min(vw * 0.92 / src.naturalWidth, vh * 0.92 / src.naturalHeight, 1);
        var w = Math.round(src.naturalWidth * s);
        var h = Math.round(src.naturalHeight * s);

        return { x: Math.round((vw - w) / 2), y: Math.round((vh - h) / 2), w: w, h: h };
    }

    function paint(el, r) {
        el.style.left = r.x + 'px';
        el.style.top = r.y + 'px';
        el.style.width = r.w + 'px';
        el.style.height = r.h + 'px';
    }

    // FLIP 起点:把大图的目标框反变换回小图当前框
    function back(src, to) {
        var r = src.getBoundingClientRect();

        return 'translate(' + (r.left - to.x) + 'px,' + (r.top - to.y) + 'px)'
            + ' scale(' + (r.width / to.w) + ',' + (r.height / to.h) + ')';
    }

    function close() {
        if (!lb || closing) {
            return;
        }

        closing = true;
        var to = target(srcImg);
        var t = back(srcImg, to);
        var current = getComputedStyle(lbImg).transform;

        lb.classList.remove('open');
        lbImg.animate([{ transform: current === 'none' ? 'none' : current }, { transform: t }],
            { duration: CLOSE, easing: EASE });

        window.setTimeout(function () {
            lb.remove();
            lb = lbImg = srcImg = null;
            closing = false;
        }, CLOSE);

        document.removeEventListener('keydown', onKey);
    }

    function onKey(e) {
        if (e.key === 'Escape') {
            close();
        }
    }

    function open(src) {
        if (lb || closing) {
            return;
        }

        srcImg = src;
        lb = document.createElement('div');
        lb.className = 'lightbox';
        lb.setAttribute('role', 'dialog');
        lb.setAttribute('aria-modal', 'true');

        lbImg = document.createElement('img');
        lbImg.src = src.currentSrc || src.src;
        lbImg.alt = src.alt || '';
        lbImg.style.visibility = 'hidden';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lb-close';
        btn.setAttribute('aria-label', '关闭');

        lb.appendChild(lbImg);
        lb.appendChild(btn);
        document.body.appendChild(lb);

        var run = function () {
            if (!lb) {
                return;
            }

            var to = target(src);
            paint(lbImg, to);
            var t = back(src, to);

            lbImg.style.transform = t;
            lbImg.style.visibility = 'visible';
            void lb.offsetWidth; // 先落首帧再开过渡,否则会从终态直接跳
            lb.classList.add('open');

            var a = lbImg.animate([{ transform: t }, { transform: 'none' }],
                { duration: OPEN, easing: EASE });
            a.onfinish = function () {
                if (lbImg) {
                    lbImg.style.transform = 'none';
                }
            };
        };

        if (lbImg.complete && lbImg.naturalWidth) {
            run();
        } else {
            lbImg.addEventListener('load', run, { once: true });
            lbImg.addEventListener('error', function () { close(); }, { once: true });
        }

        lb.addEventListener('click', close);
        document.addEventListener('keydown', onKey);
    }

    content.addEventListener('click', function (e) {
        var img = e.target && e.target.closest ? e.target.closest('img') : null;

        if (!img || !content.contains(img) || img.closest('a') || !img.naturalWidth) {
            return;
        }

        e.preventDefault();
        open(img);
    });
})();
