/* 墨林 - Molinink · 作者 Kira · https://www.molinink.com */

/*
 * 后台交互。全站无框架、无构建。
 * 这一层只是"增强":把本文件删掉,媒体库仍能选人、翻页、筛选、上传与删除
 * (选中态走 ?sel=,删除确认走 ?confirm=trash,详情字段无 JS 时按 Enter 交表)。
 */
/* 界面词由服务端注入(window.MOLIN_I18N,键=源文案);没注入就退中文源文案 */
var molinT = function (s) {
    return window.MOLIN_I18N && window.MOLIN_I18N[s] ? window.MOLIN_I18N[s] : s;
};

/* 带占位符的界面词:%s 依次替换 */
var molinTf = function (s, a, b) {
    var t = molinT(s);
    if (a !== undefined) { t = t.replace('%s', a); }
    if (b !== undefined) { t = t.replace('%s', b); }
    return t;
};

/* 右下角浮窗(《76》§5.7):服务端出首屏那两条(data-toast-auto),这里管自动消隐;
   运行期还有话要说就调 molinToast(文本, 'ok' | 'err')。同屏最多留三条,多了从最旧挤掉 */
var molinToast = function (text, kind) {
    var host = document.getElementById('toasts');

    if (host === null || !text) {
        return null;
    }

    var box = document.createElement('div');
    box.className = 'toast ' + (kind === 'err' ? 'err' : 'ok');
    box.setAttribute('role', kind === 'err' ? 'alert' : 'status');
    box.textContent = text;
    host.appendChild(box);

    while (host.children.length > 3) {
        host.removeChild(host.firstElementChild);
    }

    window.setTimeout(function () {
        box.classList.add('out');
        window.setTimeout(function () {
            if (box.parentNode !== null) {
                box.parentNode.removeChild(box);
            }
        }, 260);
    }, kind === 'err' ? 6200 : 3600);

    return box;
};

/* 复制按钮:写入剪贴板 + 短暂反馈(《80》§6)。片段换进来后要重新绑,故抽成可复用的一支 */
var bindCopy = function (root) {
    [].forEach.call((root || document).querySelectorAll('[data-copy]'), function (button) {
        if (button.getAttribute('data-copy-bound') === '1') {
            return;
        }

        button.setAttribute('data-copy-bound', '1');
        button.addEventListener('click', function () {
            var text = button.getAttribute('data-copy');
            var done = function () {
                button.textContent = molinT('已复制');
                setTimeout(function () { button.textContent = molinT('复制'); }, 1200);
            };

            var legacy = function () {
                var box = document.createElement('textarea');
                box.value = text;
                box.setAttribute('readonly', '');
                box.style.position = 'absolute';
                box.style.left = '-9999px';
                document.body.appendChild(box);
                box.select();

                try {
                    document.execCommand('copy');
                } catch (e) {
                    // 剪贴板被策略挡死时不弹错:地址本来就显示在旁边,人能自己选
                }

                document.body.removeChild(box);
                done();
            };

            // 无头浏览器与部分内网环境下 clipboard 会 reject,那条路也要给人反馈
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, legacy);

                return;
            }

            legacy();
        });
    });
};

(function () {
    'use strict';

    /* 首屏那两条浮窗走同一套自动消隐 */
    [].forEach.call(document.querySelectorAll('#toasts .toast[data-toast-auto]'), function (box) {
        window.setTimeout(function () {
            box.classList.add('out');
            window.setTimeout(function () {
                if (box.parentNode !== null) {
                    box.parentNode.removeChild(box);
                }
            }, 260);
        }, box.classList.contains('err') ? 6200 : 3600);
    });

    var content = document.querySelector('.content');
    var bar = document.querySelector('.scrollbar');
    var thumb = document.querySelector('.scrollbar .thumb');

    /* 叠加式滚动条:滚动时显,停 900ms 后隐(《76》§5.6) */
    if (content && bar && thumb) {
        var paint = function () {
            var view = content.clientHeight;
            var full = content.scrollHeight;

            if (full <= view) {
                bar.classList.remove('show');

                return;
            }

            var h = Math.max(28, (view / full) * view);
            thumb.style.height = h + 'px';
            thumb.style.top = ((content.scrollTop / (full - view)) * (view - h)) + 'px';
        };
        var hide;

        content.addEventListener('scroll', function () {
            paint();
            bar.classList.add('show');
            clearTimeout(hide);
            hide = setTimeout(function () { bar.classList.remove('show'); }, 900);
        }, { passive: true });
        window.addEventListener('resize', paint);
        paint();
    }

    /*
     * 一个遮罩两用:窄屏当抽屉的背景,选中项要删除时当对话框的底(《80》§5、§10.3)。
     * 谁开着由 DOM 决定 —— 抽屉看 .sidebar.open,对话框看 .dlg 是否 hidden。
     */
    var menuBtn = document.querySelector('.menu-btn');
    var sidebar = document.querySelector('.sidebar');
    var scrim = document.querySelector('.scrim');
    var chooser = document.getElementById('img-choose');

    /* 一个遮罩里并排住了两张框(图从哪儿来 + 媒体库),问"开着的是谁"要看可见性,
       只抓第一个 .dlg 会把后来居上的选图框当成媒体库,媒体库的取消链就断了 */
    var visibleDialog = function () {
        if (chooser !== null && chooser.hidden === false) {
            return chooser;
        }

        return document.querySelector('.scrim .dlg:not([hidden])');
    };

    var dialogOpen = function () {
        return visibleDialog() !== null;
    };

    var setDrawer = function (open) {
        if (menuBtn === null || sidebar === null) {
            return;
        }

        menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        sidebar.classList.toggle('open', open);

        if (scrim !== null && !dialogOpen()) {
            scrim.classList.toggle('open', open);
        }
    };

    if (menuBtn !== null && sidebar !== null) {
        menuBtn.addEventListener('click', function () {
            setDrawer(menuBtn.getAttribute('aria-expanded') !== 'true');
        });
    }

    /* 焦点进对话框、背景不可 Tab;Esc 与点遮罩 = 取消(《41》§11 + 《80》§10.3) */
    var background = [content, sidebar].filter(Boolean);

    var shutDialog = function () {
        var open = visibleDialog();

        if (scrim !== null) {
            scrim.classList.remove('open');
        }

        background.forEach(function (node) {
            node.removeAttribute('inert');
        });

        if (open === null) {
            return;
        }

        if (chooser !== null && open === chooser) {
            chooser.hidden = true;

            return;
        }

        var cancel = open.querySelector('[data-cancel]');

        if (cancel !== null) {
            window.location.href = cancel.getAttribute('href');
        }
    };

    if (dialogOpen() && scrim !== null) {
        var first = visibleDialog();
        scrim.classList.add('open');
        background.forEach(function (node) {
            node.setAttribute('inert', '');
        });

        // 焦点进对话框就够了,不必抢某个钮:Tab 自然走到里面第一个控件,
        // 而默认那张基准图上也没有哪个钮带着聚焦光环
        first.setAttribute('tabindex', '-1');
        first.focus({ preventScroll: true });
    }

    if (scrim !== null) {
        scrim.addEventListener('click', function (event) {
            if (event.target !== scrim) {
                return;
            }

            if (dialogOpen()) {
                shutDialog();

                return;
            }

            setDrawer(false);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        if (dialogOpen()) {
            shutDialog();

            return;
        }

        setDrawer(false);
    });

    /* 复制文件地址(《80》§6):首屏绑定,片段换进来时 bindCopy 再补 */
    bindCopy(document);

    /* 媒体库选中:点哪张看哪张,不整页刷新(无 JS 时退化成 .nm-link 的 GET)。
       POST 详情端点拿回片段:网格换右栏 #media-detail,列表在被点那行后展开 .qedit */
    (function () {
        var cols = document.querySelector('.cols');

        if (cols === null || !window.fetch) {
            return;
        }

        var field = cols.getAttribute('data-csrf-field') || '';
        var token = cols.getAttribute('data-csrf-token') || '';
        var gridCells = document.querySelectorAll('.grid .cell[data-detail-url]');
        var listRows = document.querySelectorAll('.table .tr[data-detail-url]');

        var load = function (url, done) {
            var body = new FormData();
            body.append(field, token);

            fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (answer) {
                if (answer.ok) {
                    return answer.text();
                }

                throw new Error('detail');
            }).then(done, function () {
                molinToast(molinT('详情没取回来,刷新页面重试'), 'err');
            });
        };

        var remember = function (url) {
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', url);
            }
        };

        [].forEach.call(gridCells, function (cell) {
            cell.addEventListener('click', function (event) {
                event.preventDefault();

                load(cell.getAttribute('data-detail-url'), function (html) {
                    var aside = document.getElementById('media-detail');

                    if (aside === null) {
                        aside = document.createElement('aside');
                        aside.className = 'detail';
                        aside.id = 'media-detail';
                        cols.appendChild(aside);
                    }

                    aside.innerHTML = html;

                    [].forEach.call(gridCells, function (other) {
                        other.classList.toggle('sel', other === cell);
                    });
                    remember(cell.getAttribute('href'));
                    bindCopy(aside);
                });
            });
        });

        [].forEach.call(listRows, function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('.ops, input, button')) {
                    return;
                }

                if (event.target.closest('a') && !event.target.closest('.nm-link')) {
                    return;
                }

                event.preventDefault();

                load(row.getAttribute('data-detail-url'), function (html) {
                    var open = document.querySelectorAll('.qedit');

                    for (var i = 0; i < open.length; i++) {
                        open[i].parentNode.removeChild(open[i]);
                    }

                    var box = document.createElement('div');
                    box.innerHTML = html;
                    row.parentNode.insertBefore(box.firstElementChild, row.nextSibling);

                    [].forEach.call(listRows, function (other) {
                        other.classList.toggle('sel', other === row);

                        var box2 = other.querySelector('.cb');

                        if (box2 !== null) {
                            box2.checked = other === row;
                        }
                    });
                    remember(row.getAttribute('data-href'));
                    bindCopy(row.nextElementSibling);
                });
            });
        });
    })();

    /* 详情文本域:失焦即保存(《80》§6)。无 JS 时 alt/标题按 Enter 也能交表 */
    var fields = document.getElementById('meta-form');

    if (fields !== null && window.fetch) {
        var mark = function () {
            var note = fields.querySelector('.save-note');

            if (note !== null) {
                note.classList.add('show');
                setTimeout(function () { note.classList.remove('show'); }, 1400);
            }
        };

        fields.addEventListener('change', function () {
            var body = new FormData(fields);
            body.append('xhr', '1');

            fetch(fields.getAttribute('action'), {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (answer) {
                if (answer.ok) {
                    mark();
                }
            });
        });
    }

    /* 上传:选文件或拖到页面 → 队列行 + 真实进度(《80》§10.2、《27》§1) */
    var form = document.getElementById('upload-form');
    var queue = document.getElementById('upq');

    if (form !== null && queue !== null) {
        var rowFor = function (file) {
            var row = document.createElement('div');
            row.className = 'uq';
            row.innerHTML = '<div class="un"></div>'
                + '<div class="us"><span class="stt">' + molinT('上传中') + '</span>'
                + '<span class="bar"><i style="width:0"></i></span>'
                + '<span class="pc">0%</span></div>'
                + '<div class="ua"><button type="button" class="btn btn-xs btn-ghost">' + molinT('取消') + '</button></div>';
            row.querySelector('.un').textContent = file.name;

            return row;
        };

        var settle = function () {
            queue.hidden = queue.children.length !== 0;

            if (queue.children.length === 0) {
                window.location.reload();
            }
        };

        var send = function (file) {
            var row = rowFor(file);
            queue.appendChild(row);
            queue.hidden = false;

            var body = new FormData();
            body.append('files[]', file);
            body.append('xhr', '1');
            body.append(form.getAttribute('data-csrf-field'), form.getAttribute('data-csrf-token'));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action'));

            xhr.upload.addEventListener('progress', function (event) {
                if (!event.lengthComputable) {
                    return;
                }

                var percent = Math.round((event.loaded / event.total) * 100);
                row.querySelector('.bar i').style.width = percent + '%';
                row.querySelector('.pc').textContent = percent + '%';
            });
            xhr.addEventListener('load', function () {
                // 收下的文件不再占一行:整页重载后它就在网格里
                if (xhr.status === 200 && xhr.responseText === '') {
                    queue.removeChild(row);
                    settle();

                    return;
                }

                row.outerHTML = xhr.responseText;
            });
            xhr.addEventListener('error', function () {
                var stt = row.querySelector('.stt');
                stt.textContent = molinT('✗ 没传上');
                stt.className = 'stt rej';
                row.querySelector('.bar').remove();
                row.querySelector('.pc').remove();
            });
            xhr.addEventListener('abort', function () {
                if (row.parentNode !== null) {
                    queue.removeChild(row);
                    queue.hidden = queue.children.length !== 0;
                }
            });

            row.querySelector('.ua button').addEventListener('click', function () {
                xhr.abort();
            });
            xhr.send(body);
        };

        var take = function (files) {
            [].forEach.call(files, send);
        };

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            take(form.querySelector('input[type=file]').files);
        });

        if (content !== null) {
            ['dragenter', 'dragover', 'drop'].forEach(function (name) {
                content.addEventListener(name, function (event) {
                    event.preventDefault();
                });
            });
            content.addEventListener('drop', function (event) {
                if (event.dataTransfer && event.dataTransfer.files.length) {
                    take(event.dataTransfer.files);
                }
            });
        }
    }
    /*
     * 编辑页(《79》§4.6 工具条、§10.3 两条带、《41》§6.2 自动保存与离开提醒)。
     * 没 JS 时正文区退回同名 textarea,存的还是那一份 HTML,不另开一条路。
     */
    var ed = document.querySelector('.ed');
    var view = document.getElementById('ed-view');
    var src = document.getElementById('ed-content');
    var edForm = document.getElementById('ed-form');

    if (ed !== null && view !== null && src !== null && edForm !== null) {
        ed.classList.add('rich');

        var edWc = document.getElementById('ed-wc');
        var edBand = document.getElementById('ed-autosave');
        var edLeave = document.getElementById('ed-leave');
        var edSaved = document.querySelector('.ed-head .saved');
        var dirty = false;
        var pending = '';
        // 标签药丸与输入框的那份合并(《79》§4.7 第 3 盒):交表与自动保存都得先合一次,
        // 否则自动保存读到的是被清空了的输入框,一存就把标签抹了
        var commitTags = function () {};

        var tally = function (text) {
            var n = text.replace(/\s+/g, '').length;

            return molinTf('共 %s 字 · 预计阅读约 %s 分钟', n.toLocaleString('zh-Hans'), Math.max(1, Math.ceil(n / 300)));
        };

        var sync = function () {
            src.value = view.innerHTML;

            if (edWc !== null) {
                edWc.textContent = tally(view.innerText);
            }
        };

        var mark = function () {
            dirty = true;
        };

        // 回车默认给段落,不要浏览器随手塞 <div>(div 过白名单会掉壳,段落就散了)
        try {
            document.execCommand('defaultParagraphSeparator', false, 'p');
        } catch (e) {
            // 老浏览器没这条命令也不打紧,正文照样能存
        }

        // 粘进来的东西只留纯文本:富文本编辑器不是过滤器,过滤交给 HtmlSanitizer
        view.addEventListener('paste', function (event) {
            event.preventDefault();
            var text = (event.clipboardData || window.clipboardData).getData('text');
            document.execCommand('insertText', false, text);
        });

        view.addEventListener('input', function () {
            sync();
            mark();
        });

        edForm.addEventListener('input', mark);
        edForm.addEventListener('change', mark);

        /* 工具条开关两态一律问浏览器选区,不问自家变量 —— 光标挪进挪出,按钮上的态才跟得上 */
        var CASTS = {
            bold: ['bold', null],
            italic: ['italic', null],
            ul: ['insertUnorderedList', null],
            blockquote: ['formatBlock', 'blockquote'],
            code: ['formatBlock', 'pre']
        };

        var pressed = function (name) {
            var cast = CASTS[name];

            if (cast === undefined) {
                return false;
            }

            if (cast[0] === 'formatBlock') {
                return String(document.queryCommandValue('formatBlock')).toLowerCase() === cast[1];
            }

            return document.queryCommandState(cast[0]);
        };

        var paintTools = function () {
            [].forEach.call(document.querySelectorAll('.tbtn[data-cmd]'), function (button) {
                var name = button.getAttribute('data-cmd');

                if (CASTS[name] === undefined) {
                    return;
                }

                var on = pressed(name);
                button.classList.toggle('on', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        };

        document.addEventListener('selectionchange', function () {
            if (document.activeElement === view || view.contains(document.activeElement)) {
                paintTools();
            }
        });

        [].forEach.call(document.querySelectorAll('.tbtn[data-cmd]'), function (button) {
            // 按钮按下若走默认行为,焦点会从正文挪到按钮上,选区随之丢:
            // 等再 focus() 回来,插入点已被摆到正文开头 —— 这正是"图总插到第一行"的病根。
            // 按住 mousedown 不放行默认,编辑器始终是当前焦点,光标留在原处
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });

            button.addEventListener('click', function () {
                var name = button.getAttribute('data-cmd');
                var cast = CASTS[name];

                view.focus();

                if (name === 'link') {
                    var href = window.prompt(molinT('链接地址'), 'https://');

                    if (href !== null && href !== '' && !/^\s*javascript:/i.test(href)) {
                        document.execCommand('createLink', false, href);
                    }
                } else if (name === 'image') {
                    // 图从哪儿来(#13):按钮自己不插东西,由后面那口选择框说了算。
                    // 它也不碰正文,不标脏 —— 标了就得先赔一次存草稿才肯放人去媒体库
                    openChooser('body', button);

                    return;
                } else if (cast !== undefined) {
                    // 再点一下 = 取消:块级回落成 p,行内交给浏览器自己翻面
                    var off = cast[0] === 'formatBlock' && pressed(name);
                    document.execCommand(cast[0], false, off ? 'p' : cast[1]);
                }

                sync();
                mark();
                paintTools();
            });
        });

        /* 段落样式:按钮开一层小菜单,菜单里挑一档,标签跟着换字(《79》§4.6)。
           菜单不用 <details> —— summary 上的样式会把开合吞掉,这里自己切 hidden 与 aria-expanded */
        var bpick = document.getElementById('ed-bpick');
        var bpickTog = document.getElementById('ed-bpick-tog');
        var bpickMenu = document.getElementById('ed-bpick-menu');
        var bpickLabel = document.getElementById('ed-bpick-label');

        var closeBpick = function () {
            if (bpickMenu !== null) {
                bpickMenu.hidden = true;
            }

            if (bpickTog !== null) {
                bpickTog.setAttribute('aria-expanded', 'false');
            }
        };

        if (bpick !== null && bpickTog !== null && bpickMenu !== null) {
            // 菜单挂 fixed 锚到按钮下方:工具栏在窄屏是条横向滚动区(overflow),absolute 会被裁没(#R54 报障)
            var anchorBpick = function () {
                var rect = bpickTog.getBoundingClientRect();
                var width = bpickMenu.offsetWidth || 132;
                var left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8);

                bpickMenu.style.position = 'fixed';
                bpickMenu.style.top = Math.round(rect.bottom + 6) + 'px';
                bpickMenu.style.left = Math.round(left) + 'px';
            };

            bpickTog.addEventListener('click', function () {
                var willOpen = bpickMenu.hidden;

                if (willOpen) {
                    anchorBpick();
                }

                bpickMenu.hidden = !willOpen;
                bpickTog.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });

            // fixed 菜单锚在视口上:页面一滚/一缩放就脱位,收掉比漂着强
            window.addEventListener('scroll', closeBpick, true);
            window.addEventListener('resize', closeBpick);

            [].forEach.call(bpickMenu.querySelectorAll('.bpick-i'), function (item) {
                item.addEventListener('click', function () {
                    view.focus();
                    document.execCommand('formatBlock', false, item.getAttribute('data-block'));

                    if (bpickLabel !== null) {
                        bpickLabel.textContent = item.textContent;
                    }

                    closeBpick();
                    sync();
                    mark();
                    paintTools();
                });
            });

            document.addEventListener('click', function (event) {
                if (!bpickMenu.hidden && !bpick.contains(event.target)) {
                    closeBpick();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !bpickMenu.hidden) {
                    closeBpick();
                }
            });
        }

        /* 落一次库:autoSave 只要回执不改状态;draftSave 是真的按「保存草稿」存 */
        var flush = function (autoSave, then) {
            commitTags();

            var body = new FormData(edForm);
            body.set('autosave', autoSave ? '1' : '0');

            if (!autoSave) {
                body.set('intent', 'draft');
            }

            window.fetch(edForm.getAttribute('action'), {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (answer) {
                return answer.text().then(function (text) {
                    return {ok: answer.ok, text: text};
                });
            }).then(function (result) {
                if (!result.ok) {
                    return;
                }

                dirty = false;

                if (autoSave) {
                    // 自动保存的回执就是那句文案,顺带把页头那句"已自动保存"让位给带子
                    edBand.querySelector('span').textContent = result.text;
                    edBand.classList.add('on');

                    if (edSaved !== null) {
                        edSaved.style.display = 'none';
                    }
                }

                // 落稳了才往下走(存草稿并离开 / 去媒体库选图都靠这一步续上;
                // 以前这里对 autoSave 直接 return,那条回调永远不来,人卡在原地)
                then();
            }).catch(function () {
                // 存不上就不走:人还在这页,东西也还在这页
            });
        };

        /* 《41》§6.2:间隔读设置里 autosave_interval(秒)——设置页把它写进 localStorage,
           兜底仍 30s;新文章还没有 id,不自动建草稿(否则半小时一堆孤儿) */
        var autosaveUrl = edForm.getAttribute('data-autosave');
        var autosaveSec = 30;
        var autosaveStored = '';

        try {
            autosaveStored = window.localStorage.getItem('molin_autosave_interval') || '';
        } catch (e) {
            autosaveStored = '';
        }

        if (/^\d+$/.test(autosaveStored)) {
            autosaveSec = Math.max(5, Math.min(600, parseInt(autosaveStored, 10)));
        }

        if (autosaveUrl !== '' && autosaveUrl !== null) {
            setInterval(function () {
                if (dirty) {
                    flush(true, function () {});
                }
            }, autosaveSec * 1000);
        }

        /* 特色图:点它先问图从哪儿来(#13)。没 JS 时 href 还是老路 —— 直接进媒体库挑 */
        var feat = document.getElementById('ed-feat');

        if (feat !== null) {
            feat.addEventListener('click', function (event) {
                event.preventDefault();
                openChooser('feat', feat);
            });
        }

        var openLeave = function (href) {
            pending = href;
            edLeave.classList.add('on');
        };

        var closeLeave = function () {
            pending = '';
            edLeave.classList.remove('on');
        };

        [].forEach.call(document.querySelectorAll('.content a, .side-nav a, .topbar .visit'), function (link) {
            link.addEventListener('click', function (event) {
                if (!dirty || event.metaKey || event.ctrlKey || link.hasAttribute('download')) {
                    return;
                }

                var href = link.getAttribute('href');

                if (href === null || href.charAt(0) === '#') {
                    return;
                }

                event.preventDefault();
                openLeave(href);
            });
        });

        [].forEach.call(edLeave.querySelectorAll('[data-leave]'), function (button) {
            button.addEventListener('click', function () {
                var way = button.getAttribute('data-leave');

                if (way === 'stay') {
                    closeLeave();

                    return;
                }

                if (way === 'go') {
                    dirty = false;
                    closeLeave();
                    window.location.href = pending;

                    return;
                }

                flush(false, function () {
                    window.location.href = pending;
                });
            });
        });

        /* 主动保存不是"离开":按下去那一刻就把脏标摘掉。浏览器原生那颗离开弹窗不可塑形、
           文案也不是墨林的,再不让它有出场机会 —— 要拦就拦应用内这口自家人写的条 */
        edForm.addEventListener('submit', function () {
            sync();
            dirty = false;
        });

        /* 标签:药丸是给人看的那一份,输入框只用来添新的;交表前再把药丸拼回输入框(《79》§4.7 第 3 盒) */
        var tagInput = document.getElementById('ed-tags');
        var chipBox = document.querySelector('.chips');
        var tags = [];

        var splitTags = function (text) {
            var seen = [];

            text.split(/[,，]/).forEach(function (name) {
                var clean = name.trim();

                if (clean !== '' && seen.indexOf(clean) === -1) {
                    seen.push(clean);
                }
            });

            return seen;
        };

        var drawChips = function () {
            if (chipBox === null) {
                return;
            }

            chipBox.textContent = '';

            tags.forEach(function (name) {
                var chip = document.createElement('span');
                chip.className = 'chip';
                chip.appendChild(document.createTextNode(name));

                var off = document.createElement('button');
                off.type = 'button';
                off.setAttribute('data-tag', name);
                off.setAttribute('aria-label', molinT('移除'));
                off.textContent = '×';
                chip.appendChild(off);
                chipBox.appendChild(chip);
            });
        };

        if (tagInput !== null && chipBox !== null) {
            tags = splitTags(tagInput.value);
            tagInput.value = '';
            drawChips();

            tagInput.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                splitTags(tagInput.value).forEach(function (name) {
                    if (tags.indexOf(name) === -1) {
                        tags.push(name);
                    }
                });
                tagInput.value = '';
                drawChips();
                mark();
            });

            chipBox.addEventListener('click', function (event) {
                var off = event.target.closest('button[data-tag]');

                if (off === null) {
                    return;
                }

                tags = tags.filter(function (name) {
                    return name !== off.getAttribute('data-tag');
                });
                drawChips();
                mark();
            });

            commitTags = function () {
                splitTags(tagInput.value).forEach(function (name) {
                    if (tags.indexOf(name) === -1) {
                        tags.push(name);
                    }
                });

                tagInput.value = tags.join(', ');
                drawChips();
            };

            edForm.addEventListener('submit', commitTags);
        }

        /* ＋ 新建分类:基准图里那一盒只有钮,输入格点了才露出来;回车当场落成一颗勾好的选项,
           名字带 «new:» 头随表交上去,后端建了再挂 —— 不惊动整页(《79》§4.7 第 2 盒) */
        var newCat = document.getElementById('ed-new-cat');
        var newCatIn = document.getElementById('ed-new-cat-in');
        var catBox = document.querySelector('.cats');

        if (newCat !== null && newCatIn !== null && catBox !== null) {
            newCat.addEventListener('click', function () {
                newCatIn.hidden = false;
                newCatIn.focus();
            });

            newCatIn.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();

                var name = newCatIn.value.trim();
                var taken = [].some.call(catBox.querySelectorAll('label > span:not(.n)'), function (span) {
                    return span.textContent === name;
                });

                if (name === '' || taken) {
                    newCatIn.value = '';

                    return;
                }

                newCatIn.value = '';
                newCatIn.hidden = true;

                var label = document.createElement('label');
                var box = document.createElement('input');
                box.type = 'checkbox';
                box.className = 'cb';
                box.name = 'categories[]';
                box.value = 'new:' + name;
                box.checked = true;

                var text = document.createElement('span');
                text.textContent = name;

                var count = document.createElement('span');
                count.className = 'n';
                count.textContent = '0';

                label.appendChild(box);
                label.appendChild(text);
                label.appendChild(count);

                // 与已有各行同构:外面那层 .catrow 是行尾「×」的落点,新建的这行还没有 id,自然没有「×」
                var row = document.createElement('div');
                row.className = 'catrow';
                row.appendChild(label);
                catBox.appendChild(row);
                mark();
            });
        }

        /* 分类盒行尾那颗「×」删的是**整个分类**(全局动作),所以先过一遍站内唯一的模态件,
           确认了再 XHR 送去管理屏那条删除端点 —— 全程不导航,没保存的稿子不会因此丢掉。
           服务端另有同口径的两道闸(category.manage + 兜底项不删),前端这里只是提前挡 */
        (function () {
            var box = document.getElementById('box-cats');

            if (box === null || !window.fetch) {
                return;
            }

            var url = box.getAttribute('data-del-url') || '';
            var field = box.getAttribute('data-csrf-field') || '';
            var token = box.getAttribute('data-csrf-token') || '';
            var scrim = null;
            var pending = null;

            var close = function () {
                if (scrim !== null) {
                    scrim.classList.remove('open');
                }

                pending = null;
            };

            var drop = function (button) {
                var body = new FormData();
                body.append(field, token);
                body.append('tax', 'category');
                body.append('term', button.getAttribute('data-term'));

                fetch(url, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (answer) {
                    return answer.json().then(function (data) {
                        return { ok: answer.ok, data: data };
                    });
                }).then(function (result) {
                    if (!result.ok || !result.data || result.data.ok !== true) {
                        molinToast(result.data && result.data.error ? result.data.error : molinT('没删成功，再试一次。'), 'err');

                        return;
                    }

                    var row = button.closest('.catrow') || button;

                    if (row.parentNode !== null) {
                        row.parentNode.removeChild(row);
                    }

                    molinToast(molinT('分类已删除'));
                    mark();
                }).catch(function () {
                    molinToast(molinT('没删成功，再试一次。'), 'err');
                });
            };

            var ask = function (button) {
                if (scrim === null) {
                    scrim = document.createElement('div');
                    scrim.className = 'scrim';
                    scrim.innerHTML =
                        '<div class="dlg" role="dialog" aria-modal="true">' +
                            '<div class="tag"></div>' +
                            '<h2></h2>' +
                            '<p class="tx2"></p>' +
                            '<div class="foot">' +
                                '<button class="btn btn-ghost" type="button" data-cancel></button>' +
                                '<button class="btn btn-danger" type="button" data-confirm></button>' +
                            '</div>' +
                        '</div>';

                    scrim.querySelector('.tag').textContent = molinT('危险操作');
                    scrim.querySelector('[data-cancel]').textContent = molinT('取消');
                    scrim.querySelector('[data-confirm]').textContent = molinT('删除');
                    scrim.querySelector('[data-cancel]').addEventListener('click', close);
                    scrim.querySelector('[data-confirm]').addEventListener('click', function () {
                        var target = pending;

                        if (target === null) {
                            return;
                        }

                        close();
                        drop(target);
                    });
                    scrim.addEventListener('click', function (event) {
                        if (event.target === scrim) {
                            close();
                        }
                    });

                    document.body.appendChild(scrim);

                    document.addEventListener('keydown', function (event) {
                        if (event.key === 'Escape') {
                            close();
                        }
                    });
                }

                pending = button;
                scrim.querySelector('h2').textContent = molinTf('删除「%s」？', button.getAttribute('data-name') || '');
                scrim.querySelector('.tx2').textContent = molinT('删掉这个分类时，它的文章不会被删，只是失去这个归属，子分类会提升到上一层。');

                window.requestAnimationFrame(function () {
                    if (scrim !== null) {
                        scrim.classList.add('open');
                    }
                });
            };

            [].forEach.call(box.querySelectorAll('.catdel'), function (button) {
                button.addEventListener('click', function () {
                    ask(button);
                });
            });
        })();

        /* ＋ 添加字段:再给一对空键值(《79》§4.7 第 6 盒) */
        var addMeta = document.getElementById('ed-add-meta');

        if (addMeta !== null) {
            addMeta.addEventListener('click', function () {
                var row = document.createElement('div');
                row.className = 'cf';

                ['键', '值'].forEach(function (label) {
                    var input = document.createElement('input');
                    input.className = 'mini-in';
                    input.type = 'text';
                    input.name = label === '键' ? 'meta_key[]' : 'meta_value[]';
                    input.placeholder = molinT(label);
                    row.appendChild(input);
                });

                addMeta.parentNode.insertBefore(row, addMeta);
                row.querySelector('input').focus();
                mark();
            });
        }

        /* 移除特色图片:thumbnail_id 归 0,盒子回到空态(存一下才算数,所以只标脏) */
        var unsetFeat = document.getElementById('ed-unset-thumb');

        if (unsetFeat !== null && feat !== null) {
            unsetFeat.addEventListener('click', function () {
                document.getElementById('ed-thumbnail').value = '0';
                feat.className = 'feat';
                feat.textContent = molinT('＋ 设置特色图片');
                unsetFeat.remove();
                mark();
            });
        }

        /* 图从哪儿来(#13):特色图与正文插图共用这一口框,谁点的就把它的两个地址先记下来。
           新建那篇还没 id(上传 / 选图都要它),首次用到时先静默建一篇草稿把地址补齐(ensureDraft)。 */
        var chooserMode = 'feat';
        // 正文插图前先记住光标:一点按钮焦点就跑去框里,原生选区可能已经丢,
        // 不记住的话插入时只会落到正文开头(第一行)
        var bodyCaret = null;
        var chooserPick = '';
        var chooserUpload = '';
        var chooserTrigger = null;
        var chooserErr = document.getElementById('img-uperr');
        var chooserBar = document.getElementById('img-upbar');
        var chooserFile = document.getElementById('img-local');
        var featErr = document.getElementById('feat-uperr');
        var featBar = document.getElementById('feat-upbar');

        var showErr = function (node, why) {
            if (node === null) {
                return;
            }

            node.textContent = why;
            node.hidden = false;
        };

        /* 进度条:宽度与那串百分比是同一份数,不做两条各走各的 */
        var setBar = function (bar, pct) {
            if (bar === null) {
                return;
            }

            bar.hidden = false;
            var fill = bar.querySelector('i');
            var pc = bar.querySelector('.pc');

            if (fill !== null) {
                fill.style.width = pct + '%';
            }

            if (pc !== null) {
                pc.textContent = pct + '%';
            }
        };

        var hideBar = function (bar) {
            if (bar !== null) {
                bar.hidden = true;
            }
        };

        var chooserFail = function (why) {
            hideBar(chooserBar);
            showErr(chooserErr, why);
        };

        var closeChooser = function () {
            if (chooser === null) {
                return;
            }

            chooser.hidden = true;

            if (scrim !== null && !dialogOpen()) {
                scrim.classList.remove('open');
            }
        };

        /* 静默建草稿(#13):把表单原样交去 autoDraft 那条口子,拿回 id 与本屏各处的地址,
           就地改掉表单与那几个按钮上的地址,之后这一屏就都按有 id 走 */
        var applyDraft = function (data) {
            if (data.edit_url) {
                edForm.setAttribute('action', data.edit_url);
                edForm.setAttribute('data-autosave', data.edit_url);
                autosaveUrl = data.edit_url;
            }

            if (data.id) {
                edForm.setAttribute('data-id', String(data.id));
            }

            var imgBtn = document.querySelector('.tbtn[data-cmd="image"]');

            if (imgBtn !== null) {
                if (data.body_pick_url) { imgBtn.setAttribute('data-pick-url', data.body_pick_url); }
                if (data.upload_url) { imgBtn.setAttribute('data-upload-url', data.upload_url); }
            }

            var featBtn = document.getElementById('ed-feat');

            if (featBtn !== null) {
                if (data.pick_url) { featBtn.setAttribute('href', data.pick_url); }
                if (data.pick_url) { featBtn.setAttribute('data-pick-url', data.pick_url); }
                if (data.upload_url) { featBtn.setAttribute('data-upload-url', data.upload_url); }
            }

            if (chooserMode === 'body') {
                chooserPick = data.body_pick_url || chooserPick;
            } else {
                chooserPick = data.pick_url || chooserPick;
            }

            chooserUpload = data.upload_url || chooserUpload;
        };

        var ensureDraft = function (next) {
            if ((edForm.getAttribute('data-id') || '') !== '') {
                next();

                return;
            }

            sync();
            commitTags();

            var body = new FormData(edForm);
            body.set('autodraft', '1');

            window.fetch(edForm.getAttribute('action'), {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (answer) {
                return answer.json().then(function (data) {
                    if (data === null || data.ok !== true || !data.id) {
                        throw new Error('no-draft');
                    }

                    applyDraft(data);
                    dirty = false;
                    next();
                });
            }).catch(function () {
                chooserFile.value = '';
                chooserFail(molinT('没能先建好草稿，稍后再试一次。'));
            });
        };

        var openChooser = function (mode, trigger) {
            if (chooser === null) {
                return;
            }

            chooserMode = mode;
            chooserTrigger = trigger;
            chooserPick = trigger.getAttribute('data-pick-url') || '';
            chooserUpload = trigger.getAttribute('data-upload-url') || '';

            if (mode === 'body') {
                var now = window.getSelection();
                bodyCaret = now.rangeCount > 0 && view.contains(now.anchorNode) ? now.getRangeAt(0).cloneRange() : null;
            }

            if (chooserErr !== null) {
                chooserErr.hidden = true;
            }

            if (featErr !== null) {
                featErr.hidden = true;
            }

            hideBar(chooserBar);
            hideBar(featBar);
            chooser.hidden = false;

            if (scrim !== null) {
                scrim.classList.add('open');
            }
        };

        var insertImage = function (url) {
            view.focus();

            var sel = window.getSelection();

            // 落点优先用打开选择框那刻记住的光标;换页回来时它随整页重载丢了,退回末尾。
            // 必须显式摆 range,不能拿 focus 之后的选区当"有落点"的依据 —— focus 会把插入点
            // 默认落到正文开头,照它插,图必进第一行(这就是插图总跑第一行的病根)
            var range = document.createRange();

            if (bodyCaret !== null && view.contains(bodyCaret.startContainer)) {
                range = bodyCaret;
            } else {
                range.selectNodeContents(view);
                range.collapse(false);
            }

            bodyCaret = null;

            sel.removeAllRanges();
            sel.addRange(range);

            document.execCommand('insertHTML', false, '<img src="' + String(url).replace(/"/g, '&quot;') + '" alt="">');
            sync();
            mark();
        };

        /* 本地上传那一趟:带实时进度(#4 #8)。特色图成功后服务端直接重定向到编辑器看结果,
           正文插图成功回 JSON 由这里插进正文 */
        var uploadChooserImage = function (url, file) {
            var isFeat = chooserMode === 'feat';

            if (chooserErr !== null) { chooserErr.hidden = true; }
            if (featErr !== null) { featErr.hidden = true; }
            setBar(chooserBar, 0);

            if (isFeat) { setBar(featBar, 0); }

            var body = new FormData();
            body.append('file', file);
            body.append('target', chooserMode);
            body.append(chooser.getAttribute('data-csrf-field'), chooser.getAttribute('data-csrf-token'));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', url);

            xhr.upload.addEventListener('progress', function (event) {
                if (!event.lengthComputable) {
                    return;
                }

                var pct = Math.round((event.loaded / event.total) * 100);
                setBar(chooserBar, pct);

                if (isFeat) { setBar(featBar, pct); }
            });

            xhr.addEventListener('load', function () {
                chooserFile.value = '';

                var data = null;

                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    data = null;
                }

                if (xhr.status >= 200 && xhr.status < 300 && isFeat) {
                    // feat 那一趟服务端连存图带落库一次办完,跟去它重定向的落点看结果。
                    // 跳转是整页重载,先把这一稿存下去 —— 不存,上次保存之后敲的字会被冲没(R54 报障)
                    setBar(chooserBar, 100);
                    setBar(featBar, 100);

                    var editGo = function () {
                        window.location.href = xhr.responseURL || window.location.href;
                    };

                    if (dirty && autosaveUrl !== '' && autosaveUrl !== null) {
                        sync();
                        commitTags();

                        var keep = new FormData(edForm);
                        keep.set('autosave', '1');
                        // 这张刚在服务端挂好,而表单里还是旧值;带上它会把新图盖回去
                        keep.delete('thumbnail_id');

                        window.fetch(edForm.getAttribute('action'), {
                            method: 'POST',
                            body: keep,
                            credentials: 'same-origin'
                        }).then(function (answer) {
                            if (answer.ok) {
                                editGo();

                                return;
                            }

                            chooserFail(molinT('图已设好，但稿子没存上；先点「保存草稿」，再离开本页。'));
                        }).catch(function () {
                            chooserFail(molinT('图已设好，但稿子没存上；先点「保存草稿」，再离开本页。'));
                        });

                        return;
                    }

                    editGo();

                    return;
                }

                if (data !== null && data.ok === true && !isFeat) {
                    // 同 picker:先把框收掉(inert 撤了)再插图
                    hideBar(chooserBar);
                    closeChooser();
                    insertImage(data.url);

                    return;
                }

                var why = data !== null && data.error ? data.error : molinT('这张图没传上，再试一次。');
                chooserFail(why);

                if (isFeat) {
                    hideBar(featBar);
                    showErr(featErr, why);
                }
            });

            xhr.addEventListener('error', function () {
                chooserFile.value = '';
                var why = molinT('这张图没传上，再试一次。');
                chooserFail(why);

                if (isFeat) {
                    hideBar(featBar);
                    showErr(featErr, why);
                }
            });

            xhr.send(body);
        };

        if (chooser !== null) {
            var chooserLib = chooser.querySelector('[data-img="lib"]');
            var chooserCancel = chooser.querySelector('[data-img="cancel"]');

            if (chooserLib !== null) {
                chooserLib.addEventListener('click', function () {
                    // 新建那篇第一次点:先把草稿建出来,地址才算得出来(#13)
                    ensureDraft(function () {
                        var trigger = chooserTrigger;
                        var target = trigger !== null ? (trigger.getAttribute('data-pick-url') || '') : chooserPick;

                        if (target === '') {
                            chooserFail(molinT('暂时拿不到媒体库地址，稍后再试。'));

                            return;
                        }

                        // 去媒体库是换页:换页前先把这一稿存下去,别让一次跳转吃掉正文(老口径)
                        if (dirty && autosaveUrl !== '' && autosaveUrl !== null) {
                            flush(true, function () { window.location.href = target; });

                            return;
                        }

                        window.location.href = target;
                    });
                });
            }

            if (chooserCancel !== null) {
                chooserCancel.addEventListener('click', closeChooser);
            }

            if (chooserFile !== null) {
                chooserFile.addEventListener('change', function () {
                    if (chooserFile.files.length === 0) {
                        return;
                    }

                    var file = chooserFile.files[0];

                    ensureDraft(function () {
                        var trigger = chooserTrigger;
                        var target = trigger !== null ? (trigger.getAttribute('data-upload-url') || '') : chooserUpload;

                        if (target === '') {
                            chooserFile.value = '';
                            chooserFail(molinT('这张图没传上，再试一次。'));

                            return;
                        }

                        uploadChooserImage(target, file);
                    });
                });
            }
        }

        /* 正文插图换页(?pick=body):点哪张就把哪张塞进正文,不再"选完没动静"。
           注意先把框收掉再插图 —— 框开着时 .content 挂着 inert,光标进不去,execCommand 白跑 */
        [].forEach.call(document.querySelectorAll('.dlg .grid .cell[data-url]'), function (cell) {
            cell.addEventListener('click', function (event) {
                event.preventDefault();

                var wide = cell.closest('.dlg');

                if (wide !== null) {
                    wide.hidden = true;
                }

                if (scrim !== null) {
                    scrim.classList.remove('open');
                }

                background.forEach(function (node) {
                    node.removeAttribute('inert');
                });

                insertImage(cell.getAttribute('data-url'));
            });
        });

        /* 地址栏下那行随敲随算:只算给人看,真正生效的仍是 PostWriter */
        var slugIn = document.getElementById('ed-slug');
        var slugNote = document.getElementById('ed-slug-note');

        if (slugIn !== null && slugNote !== null) {
            var slugBase = slugNote.getAttribute('data-base') || '';
            var slugTip = slugNote.textContent;

            slugIn.addEventListener('input', function () {
                var value = slugIn.value.trim();
                slugNote.textContent = value === '' || slugBase === '' ? slugTip : slugBase + value;
            });
        }

        sync();
        paintTools();
    }

    /*
     * 注册页的密码强度条(《122》§5)。判分只用《32》§7 给的那两条尺子:
     * 长度够不够 8 位、字符类别有没有三类以上 —— 不另立档位。
     */
    var meterRow = document.querySelector('.meter-row');
    var pw = meterRow === null ? null : document.getElementById('p');

    if (pw !== null) {
        var bar = meterRow.querySelector('.meter i');
        var txt = meterRow.querySelector('.meter-txt');

        var score = function (value) {
            if (value === '') {
                return ['—', 0];
            }

            if (value.length < 8) {
                return ['弱', 33];
            }

            var kinds = 0;

            if (/[a-z]/.test(value)) { kinds++; }
            if (/[A-Z]/.test(value)) { kinds++; }
            if (/[0-9]/.test(value)) { kinds++; }
            if (/[^A-Za-z0-9]/.test(value)) { kinds++; }

            return kinds >= 3 ? ['强', 100] : ['中', 66];
        };

        pw.addEventListener('input', function () {
            var s = score(pw.value);
            bar.style.width = s[1] + '%';
            txt.textContent = molinT('强度：') + molinT(s[0]);
        });
    }

    // 文章列表的勾选联动(《78》§6):表头全选 = 全选本页;部分选中时表头是半选横杠(§10.2)
    var all = document.getElementById('cb-all');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.page-posts .tr .cb'));

    if (all && boxes.length) {
        var picked = document.querySelector('.page-posts .picked');
        var sync = function () {
            var on = boxes.filter(function (b) { return b.checked; });

            boxes.forEach(function (b) {
                b.closest('.tr').classList.toggle('sel', b.checked);
            });

            all.checked = on.length === boxes.length;
            all.classList.toggle('indet', on.length > 0 && on.length < boxes.length);

            if (picked) {
                picked.hidden = on.length === 0;
                picked.querySelector('b').textContent = String(on.length);
            }
        };

        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            sync();
        });

        boxes.forEach(function (b) { b.addEventListener('change', sync); });
        sync();
    }
})();

// 窄屏没有「筛选」那颗按钮(《78》§5 的移动端基准图里就没有),改用改完即提交
(function () {
    var form = document.querySelector('form.filters');
    var narrow = window.matchMedia('(max-width: 768px)');

    if (!form) {
        return;
    }

    Array.prototype.forEach.call(form.querySelectorAll('select'), function (box) {
        box.addEventListener('change', function () {
            if (narrow.matches) {
                form.submit();
            }
        });
    });
})();

/*
 * 主题设置(《91》)。全是"增强":删掉这一层,字段照样能改能存,
 * 只是折叠、滑块读数、加行删行、选文件自动提交这些便捷动作会退回无 JS 的朴素行为。
 */
(function () {
    'use strict';

    /* 选文件即提交(导入设置 / 上传主题 / 上传插件 / 传头像):文件选择器本身没法配提交按钮。
       走 requestSubmit 而不是 submit() —— 后者绕开 submit 事件,而真进度条正挂在那个事件上 */
    [].forEach.call(document.querySelectorAll('input[type=file][data-auto]'), function (input) {
        input.addEventListener('change', function () {
            if (!input.files || input.files.length === 0 || input.form === null) {
                return;
            }

            if (typeof input.form.requestSubmit === 'function') {
                input.form.requestSubmit();

                return;
            }

            input.form.submit();
        });
    });

    /* 真进度条(表单带 data-progress):文件走 XHR,进度归 .upbar,回执不对落 .uperr */
    [].forEach.call(document.querySelectorAll('form[data-progress]'), function (form) {
        var bar = form.querySelector('.upbar');
        var fill = form.querySelector('.upbar i');
        var pc = form.querySelector('.upbar .pc');
        var err = form.querySelector('.uperr');

        var fail = function (why) {
            if (err === null) {
                return;
            }

            err.textContent = why;
            err.hidden = false;
        };

        form.addEventListener('submit', function (event) {
            var input = form.querySelector('input[type=file]');

            if (input === null || !input.files || input.files.length === 0) {
                return;
            }

            event.preventDefault();

            if (err !== null) {
                err.hidden = true;
            }

            if (bar !== null) {
                bar.hidden = false;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action'));

            if (fill !== null && pc !== null) {
                xhr.upload.addEventListener('progress', function (progress) {
                    if (!progress.lengthComputable) {
                        return;
                    }

                    var percent = Math.round((progress.loaded / progress.total) * 100);
                    fill.style.width = percent + '%';
                    pc.textContent = percent + '%';
                });
            }

            xhr.addEventListener('load', function () {
                // 服务端收下后还有一步"解包 / 建目录 / 验图"要跑,回执落在哪页就去哪页:
                // 成功是结果页,失败是带着错因回本页(PRG),两条都由服务端说了算
                if (xhr.status >= 200 && xhr.status < 400) {
                    window.location.href = xhr.responseURL || window.location.href;

                    return;
                }

                var data = null;

                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    data = null;
                }

                if (bar !== null) {
                    bar.hidden = true;
                }

                fail(data !== null && data.error ? data.error : molinT('没传上，再试一次。'));
            });

            xhr.addEventListener('error', function () {
                if (bar !== null) {
                    bar.hidden = true;
                }

                fail(molinT('没传上，再试一次。'));
            });

            xhr.send(new FormData(form));
        });
    });

    /* 分组折叠:只动 UI 态,不动数据 */
    [].forEach.call(document.querySelectorAll('.theme-settings .gh'), function (head) {
        head.addEventListener('click', function () {
            var closed = head.parentElement.classList.toggle('closed');

            head.setAttribute('aria-expanded', closed ? 'false' : 'true');
        });
    });

    /* 开关右侧那两个字跟着勾选态走 */
    [].forEach.call(document.querySelectorAll('.theme-settings .switch input[data-switch]'), function (box) {
        box.addEventListener('change', function () {
            var txt = box.closest('.switch').querySelector('.txt');

            if (txt !== null) {
                txt.textContent = box.checked ? molinT('开启') : molinT('关闭');
            }
        });
    });

    /* 整行可点:开关本体只有 40×22,手机上手一偏就落在字段名或行内空白上,点了毫无反应。
       这里把整行变成热区;但"正在选字"时不翻值 —— 2026-10-16 作者提过想选字却把开关翻过去。 */
    [].forEach.call(document.querySelectorAll('.theme-settings .field'), function (field) {
        var box = field.querySelector('.switch input[type=checkbox][data-switch]');

        if (box === null) {
            return;
        }

        field.style.cursor = 'pointer';

        /* 开关本体(点轨道/圆点/右边两个字)自己兜一层:有的手机浏览器点 label 里的图标
           不会触发原生勾选(2026-10-18 作者在 Firefox 安卓上实测:点图标没反应、点同一行别处
           才有反应)。这里直接自己翻值,并 preventDefault 掉原生行为,免得翻两次。
           点中 input 本身(键盘空格)时直接放行,交给浏览器。 */
        box.closest('.switch').addEventListener('click', function (event) {
            if (event.target === box) {
                return;
            }

            if (window.getSelection && String(window.getSelection()).length > 0) {
                return;
            }

            event.preventDefault();
            box.checked = !box.checked;
            box.dispatchEvent(new Event('change', { bubbles: true }));
        });

        field.addEventListener('click', function (event) {
            if (event.target.closest('.switch') !== null) {
                return;
            }

            if (window.getSelection && String(window.getSelection()).length > 0) {
                return;
            }

            box.checked = !box.checked;
            box.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    /* 主题设置：有改动就把底部状态改成「未保存」，别让人以为开关点完就已经生效 */
    (function () {
        var form = document.getElementById('settings-form');
        var status = document.querySelector('.savebar .status');

        if (form === null || status === null) {
            return;
        }

        var dirty = false;

        var markDirty = function () {
            if (dirty) {
                return;
            }

            dirty = true;
            status.innerHTML = '<b>' + molinT('有未保存的修改') + '</b> · ' + molinT('点「保存」后前台生效');
            status.classList.add('dirty');
        };

        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('submit', function () {
            dirty = false;
        });
    })();

    /* 滑块读数 */
    [].forEach.call(document.querySelectorAll('.theme-settings input[data-range]'), function (range) {
        range.addEventListener('input', function () {
            var rv = range.parentElement.querySelector('.rv');

            if (rv !== null) {
                rv.textContent = range.value;
            }
        });
    });

    /* repeater:＋ 添加一项 / 删除 */
    [].forEach.call(document.querySelectorAll('.theme-settings .rep[data-rep]'), function (rep) {
        var prefix = rep.getAttribute('data-prefix') || '';
        var subFields = [];

        try {
            subFields = JSON.parse(rep.getAttribute('data-sub') || '[]');
        } catch (e) {
            subFields = [];
        }

        rep.addEventListener('click', function (event) {
            var add = event.target.closest('[data-radd]');
            var remove = event.target.closest('[data-rmrow]');

            if (remove !== null) {
                remove.closest('.ritem').remove();

                return;
            }

            if (add === null) {
                return;
            }

            var items = rep.querySelectorAll('.ritem');
            var index = items.length;
            var row = document.createElement('div');
            var idx = document.createElement('span');

            row.className = 'ritem';
            idx.className = 'idx';
            idx.textContent = String(index + 1);
            row.appendChild(idx);

            subFields.forEach(function (sub) {
                var input = document.createElement('input');

                input.type = 'text';
                input.name = prefix + '[' + index + '][' + sub.key + ']';
                row.appendChild(input);
            });

            var del = document.createElement('button');

            del.className = 'lnk';
            del.type = 'button';
            del.setAttribute('data-rmrow', '');
            del.textContent = molinT('删除');
            row.appendChild(del);

            rep.insertBefore(row, add);
            row.querySelector('input').focus();
        });
    });

    /* 图片字段「移除」:隐藏值归 0,保存时生效 */
    [].forEach.call(document.querySelectorAll('.theme-settings [data-unset]'), function (button) {
        button.addEventListener('click', function () {
            var box = document.getElementById(button.getAttribute('data-unset'));

            if (box !== null) {
                box.value = '0';
            }

            var pick = button.closest('.pick');
            var fn = pick.querySelector('.fn');
            var fs = pick.querySelector('.fs');
            var pv = pick.querySelector('.pv');

            if (fn !== null) {
                fn.textContent = molinT('还没选图片');
            }

            if (fs !== null) {
                fs.textContent = molinT('从媒体库选一张，或直接传一张');
            }

            // 预览图跟着一起撤:别留着上一张的图,让人以为还选着
            if (pv !== null) {
                pv.textContent = '';
            }

            button.remove();
        });
    });

    /* 色块即取色器:原生 color 只负责挑色,写回旁边那个文本框,由文本框统一提交 */
    [].forEach.call(document.querySelectorAll('.theme-settings .color'), function (wrap) {
        var picker = wrap.querySelector('input[type=color]');
        var text = wrap.querySelector('input[type=text]');
        var sw = wrap.querySelector('.sw');

        if (picker === null || text === null) {
            return;
        }

        var paint = function (value) {
            if (sw !== null) {
                sw.style.background = value;
            }
        };

        picker.addEventListener('input', function () {
            text.value = picker.value;
            paint(picker.value);
            text.dispatchEvent(new Event('input', { bubbles: true }));
        });

        text.addEventListener('input', function () {
            var value = text.value.trim();

            if (/^#[0-9a-f]{6}$/i.test(value)) {
                picker.value = value;
                paint(value);
            }
        });
    });

    /* 校验失败:汇总条上的「跳到第一个错误」滚到中间并聚焦到出错的输入框 */
    [].forEach.call(document.querySelectorAll('.theme-settings [data-jump-err]'), function (link) {
        link.addEventListener('click', function (event) {
            var target = document.getElementById((link.getAttribute('href') || '').replace('#', ''));

            if (target === null) {
                return;
            }

            event.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });

            var box = target.querySelector('input:not([type=hidden]), select, textarea');

            if (box !== null) {
                box.focus({ preventScroll: true });
            }
        });
    });

    /* 分组「恢复默认」的确认浮层:取消即收起(保存栏那份另有一段) */
    [].forEach.call(document.querySelectorAll('.theme-settings [data-close]'), function (button) {
        button.addEventListener('click', function () {
            var box = button.closest('details');

            if (box !== null) {
                box.open = false;
            }
        });
    });

    /* 改了没保存就关页面 / 跳走:浏览器问一次;「导出设置」这类只读动作不拦(data-noleave) */
    (function () {
        var form = document.getElementById('settings-form');

        if (form === null) {
            return;
        }

        var changed = false;
        var submitting = false;

        form.addEventListener('input', function () {
            changed = true;
        });

        form.addEventListener('change', function () {
            changed = true;
        });

        form.addEventListener('submit', function () {
            submitting = true;
        });

        [].forEach.call(document.querySelectorAll('a[data-noleave]'), function (link) {
            link.addEventListener('click', function () {
                changed = false;
            });
        });

        window.addEventListener('beforeunload', function (event) {
            if (!changed || submitting) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';

            return '';
        });
    })();

    /* repeater 删行后重排序号与 name 里的下标,别给后端留 0、2 这样的空档 */
    (function () {
        var reps = document.querySelectorAll('.theme-settings .rep[data-rep]');

        [].forEach.call(reps, function (rep) {
            var prefix = rep.getAttribute('data-prefix') || '';

            rep.addEventListener('click', function (event) {
                if (event.target.closest('[data-rmrow]') === null) {
                    return;
                }

                window.setTimeout(function () {
                    [].forEach.call(rep.querySelectorAll('.ritem'), function (row, index) {
                        var idx = row.querySelector('.idx');

                        if (idx !== null) {
                            idx.textContent = String(index + 1);
                        }

                        [].forEach.call(row.querySelectorAll('input'), function (input) {
                            input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
                        });
                    });
                }, 0);
            });
        });
    })();
    /* 保存栏里的重置确认:取消即收起浮层 */
    [].forEach.call(document.querySelectorAll('.savebar [data-close]'), function (button) {
        button.addEventListener('click', function () {
            var box = button.closest('details');

            if (box !== null) {
                box.open = false;
            }
        });
    });

    /* 个人资料:改动跟踪(离开前提醒;「放弃修改」的二次确认只在有改动时出现) */
    (function () {
        var form = document.getElementById('profile-form');

        if (form === null) {
            return;
        }

        var dirty = false;
        var switchBox = form.querySelector('.switch input[type=checkbox][data-switch]');
        var syncSwitch = function () {
            if (switchBox === null) {
                return;
            }

            var txt = switchBox.closest('.switch').querySelector('.txt');

            if (txt !== null) {
                txt.textContent = switchBox.checked ? molinT('已开启') : molinT('已关闭');
            }
        };

        [].forEach.call(form.querySelectorAll('input, select, textarea'), function (el) {
            el.addEventListener('input', function () { dirty = true; });
            el.addEventListener('change', function () { dirty = true; });
        });

        form.addEventListener('submit', function () { dirty = false; });

        /* 有改动还从链接走:弹一口墨林自己的框。原生那颗 beforeunload 不可塑形、文案也不归墨林管,
           作者已点名不要它 —— 宁可松开"直接关标签页"那条路,也要留住应用内的体面 */
        var leaveBox = null;

        var closeLeave = function () {
            if (leaveBox !== null) {
                leaveBox.remove();
                leaveBox = null;
            }
        };

        var openLeave = function (href) {
            if (leaveBox !== null) {
                return;
            }

            leaveBox = document.createElement('div');
            leaveBox.className = 'scrim open';
            leaveBox.innerHTML = '<div class="dlg leave" role="dialog" aria-modal="true" tabindex="-1">'
                + '<h2></h2>'
                + '<div class="lk">'
                + '<button type="button" class="btn btn-ghost" data-lk="stay"></button>'
                + '<button type="button" class="btn btn-primary" data-lk="go"></button>'
                + '</div>'
                + '</div>';
            leaveBox.querySelector('h2').textContent = molinT('修改还没保存');
            leaveBox.querySelector('[data-lk="stay"]').textContent = molinT('留在本页');
            leaveBox.querySelector('[data-lk="go"]').textContent = molinT('仍要离开');

            leaveBox.querySelector('[data-lk="stay"]').addEventListener('click', closeLeave);
            leaveBox.querySelector('[data-lk="go"]').addEventListener('click', function () {
                dirty = false;
                closeLeave();
                window.location.href = href;
            });
            leaveBox.addEventListener('click', function (event) {
                if (event.target === leaveBox) {
                    closeLeave();
                }
            });

            document.body.appendChild(leaveBox);
            leaveBox.querySelector('.dlg').focus();
        };

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && leaveBox !== null) {
                closeLeave();
            }
        });

        [].forEach.call(document.querySelectorAll('.content a, .side-nav a, .topbar .visit'), function (link) {
            link.addEventListener('click', function (event) {
                if (!dirty || event.metaKey || event.ctrlKey || link.hasAttribute('download')) {
                    return;
                }

                var href = link.getAttribute('href');

                if (href === null || href.charAt(0) === '#') {
                    return;
                }

                event.preventDefault();
                openLeave(href);
            });
        });

        form.addEventListener('reset', function () {
            window.setTimeout(function () {
                dirty = false;
                syncSwitch();
            }, 0);
        });

        var rst = document.querySelector('.page-profile .savebar .rst');

        if (rst === null) {
            return;
        }

        var summary = rst.querySelector('summary');

        if (summary !== null) {
            // 没改动就没有什么可放弃的:不弹确认
            summary.addEventListener('click', function (e) {
                if (!dirty) {
                    e.preventDefault();
                }
            });
        }

        var confirmBtn = rst.querySelector('button[data-rst]');

        if (confirmBtn !== null) {
            confirmBtn.addEventListener('click', function () {
                rst.removeAttribute('open');
            });
        }
    })();

    /* 日志(《112》§6):移动端没有「筛选」按钮 —— 两个下拉一变就提交;桌面仍走「筛选」 */
    var logsForm = document.getElementById('logs-filters');

    if (logsForm !== null && window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
        [].forEach.call(logsForm.querySelectorAll('select'), function (box) {
            box.addEventListener('change', function () { logsForm.submit(); });
        });
    }

    /* 用户新建与编辑(《106》§5.6):密码强度按四格亮。判分沿用注册页那两条尺子(《32》§7),
       只把档位映射成格数:—0 / 弱1 / 中3 / 强4;欢迎开关右侧的字跟着勾选态走 */
    (function () {
        var form = document.getElementById('user-form');

        if (form === null) {
            return;
        }

        var pw = form.querySelector('.pw input[type=password]');
        var bars = form.querySelectorAll('.meter i');
        var lv = form.querySelector('.mrow .lv');

        if (pw !== null && bars.length > 0 && lv !== null) {
            var score = function (value) {
                if (value === '') {
                    return ['—', 0];
                }

                if (value.length < 8) {
                    return ['弱', 1];
                }

                var kinds = 0;

                if (/[a-z]/.test(value)) { kinds++; }
                if (/[A-Z]/.test(value)) { kinds++; }
                if (/[0-9]/.test(value)) { kinds++; }
                if (/[^A-Za-z0-9]/.test(value)) { kinds++; }

                return kinds >= 3 ? ['强', 4] : ['中', 3];
            };

            pw.addEventListener('input', function () {
                var s = score(pw.value);

                for (var i = 0; i < bars.length; i++) {
                    bars[i].classList.toggle('on', i < s[1]);
                }

                lv.textContent = molinT('强度 · ') + molinT(s[0]);
            });
        }

        var switchBox = form.querySelector('.switch input[type=checkbox][data-switch]');
        var txt = switchBox === null ? null : switchBox.closest('.switch').querySelector('.txt');

        if (switchBox !== null && txt !== null) {
            switchBox.addEventListener('change', function () {
                txt.textContent = switchBox.checked ? molinT('已开启') : molinT('已关闭');
            });
        }
    })();
})();

/*
 * 长任务进度(表单带 data-prog-job:备份与恢复)。
 * 这类活儿在一条请求里跑完,浏览器只能另开一条请求问"到哪了"(data-prog-url)。
 * 提交走 XHR 不跳页,边上按 700ms 轮进度:阶段进 .pstage,百分比进 .upbar;
 * 回执一到就照旧跳转,最终成败由服务端 PRG 后首屏那条浮窗讲。
 * 单槽位契约:job 名对不上、或还没开跑就撞见上一轮的收尾态,一律当没看见。
 */
(function () {
    'use strict';

    [].forEach.call(document.querySelectorAll('form[data-prog-job]'), function (form) {
        var job = form.getAttribute('data-prog-job');
        var url = form.getAttribute('data-prog-url') || '';
        var bar = form.querySelector('.upbar');
        var fill = form.querySelector('.upbar i');
        var pc = form.querySelector('.upbar .pc');
        var stage = form.querySelector('.pstage');
        var err = form.querySelector('.uperr');
        var button = form.querySelector('button[type=submit]');
        var timer = null;
        var seenRunning = false;

        var stageText = function (text) {
            if (stage !== null && text) {
                stage.textContent = text;
                stage.hidden = false;
            }
        };

        var tidy = function () {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }

            if (bar !== null) {
                bar.hidden = true;
            }

            if (stage !== null) {
                stage.hidden = true;
            }
        };

        var poll = function () {
            var xhr = new XMLHttpRequest();

            xhr.open('GET', url, true);
            xhr.addEventListener('load', function () {
                var data = null;

                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    return;
                }

                // 单槽位:不是这一轮、或还没开跑 —— 上一轮的收尾态别拿来吓人
                if (data === null || data.job !== job) {
                    return;
                }

                if (data.running === true) {
                    seenRunning = true;

                    var percent = Math.max(0, Math.min(100, parseInt(data.percent, 10) || 0));

                    if (fill !== null) { fill.style.width = percent + '%'; }
                    if (pc !== null) { pc.textContent = percent + '%'; }

                    stageText(data.label || data.phase || '');
                }

                if (seenRunning && data.running === false && data.ok === false) {
                    tidy();

                    if (button !== null) { button.disabled = false; }

                    if (err !== null) {
                        err.textContent = data.message || molinT('任务没有完成，详情见运行日志。');
                        err.hidden = false;
                    }
                }
            });
            xhr.send();
        };

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (err !== null) { err.hidden = true; }
            if (bar !== null) { bar.hidden = false; }
            if (fill !== null) { fill.style.width = '0%'; }
            if (pc !== null) { pc.textContent = '0%'; }
            if (button !== null) { button.disabled = true; }

            stageText(molinT('正在准备…'));

            seenRunning = false;
            timer = window.setInterval(poll, 700);

            var xhr = new XMLHttpRequest();

            xhr.open('POST', form.getAttribute('action'));
            xhr.addEventListener('load', function () {
                if (xhr.status >= 200 && xhr.status < 400) {
                    window.location.href = xhr.responseURL || window.location.href;

                    return;
                }

                tidy();

                if (button !== null) { button.disabled = false; }

                var data = null;

                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    data = null;
                }

                if (err !== null) {
                    err.textContent = data !== null && data.error ? data.error : molinT('没跑成，再试一次。');
                    err.hidden = false;
                }
            });
            xhr.addEventListener('error', function () {
                tidy();

                if (button !== null) { button.disabled = false; }

                if (err !== null) {
                    err.textContent = molinT('没跑成，再试一次。');
                    err.hidden = false;
                }
            });
            xhr.send(new FormData(form));
        });
    });
})();

/*
 * 远端凭据「测试连接」(《109》§12):拿表单当前值探一次真写入(S3 / WebDAV / FTP)。
 * 不跳页 —— 结果就地写进 .rtest,所以填了一半也能先测;按钮自己是 type=button,不交表。
 */
(function () {
    'use strict';

    var button = document.querySelector('[data-remote-test]');

    if (button === null) {
        return;
    }

    var form = button.form;
    var out = document.querySelector('.rtest');

    var say = function (text, bad) {
        if (out === null) {
            return;
        }

        out.textContent = text;
        out.hidden = false;
        out.className = bad ? 'rtest bad' : 'rtest';
    };

    var settle = function (data) {
        button.disabled = false;

        if (data === null || typeof data.message !== 'string') {
            say(molinT('没测成，再试一次。'), true);

            return;
        }

        say(data.message, data.ok !== true);
    };

    button.addEventListener('click', function () {
        if (form === null) {
            return;
        }

        var xhr = new XMLHttpRequest();

        button.disabled = true;
        say(molinT('正在测试…'), false);

        xhr.open('POST', button.getAttribute('data-remote-test') || '');
        xhr.addEventListener('load', function () {
            var data = null;

            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                data = null;
            }

            settle(data);
        });
        xhr.addEventListener('error', function () {
            settle(null);
        });
        xhr.send(new FormData(form));
    });
})();

/*
 * 设置页增强(《76》)。服务端已把每组分组的当前值画好;这里只补四类"看着没反应"的地方:
 * 固定链接三处联动、静态首页 / 自定义头像的展开、重置二次确认、清理缓存与检查更新。
 * 页面上没有 #settings-form 就整块不跑(别的屏不引这文件也进不来)。
 */
(function () {
    'use strict';

    var form = document.getElementById('settings-form');

    if (form === null) {
        return;
    }

    var csrfField = form.getAttribute('data-csrf-field') || '';
    var csrfToken = form.getAttribute('data-csrf-token') || '';

    /* 自动保存间隔(秒):设置页改动即时写进 localStorage,编辑页的定时器读它。
       隐私模式把 localStorage 关了也不碍事——写不进就写不进,编辑页退回默认 30s */
    var autosaveInterval = form.getAttribute('data-autosave-interval') || '';

    if (/^\d+$/.test(autosaveInterval)) {
        try {
            window.localStorage.setItem('molin_autosave_interval', autosaveInterval);
        } catch (e) {
            // 存储被禁:编辑页用默认间隔
        }
    }

    /* 「选中即生效」的下拉:选了就提交整表,成功就刷新 —— 不等「保存更改」。
       由头(《76》§216):设置页的分组页签是整页跳转,选完不保存就切页会丢改动;
       而这类字段丢了还看不出来(页面照样显示旧值),所以必须走近路。
       现在两处:站点语言、更新通道 —— 后者尤其容易踩:那页上「检查更新」一颗按钮就跳去更新页,
       选完通道顺手点它 = 整页跳走,改动当场没了,到了更新页还以为是"存不上"。 */
    var immediateSelect = function (name) {
        var select = form.querySelector('select[name="' + name + '"]');

        if (select === null || !window.fetch) {
            return;
        }

        select.addEventListener('change', function () {
            var body = new FormData(form);

            body.append('xhr', '1');

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (answer) {
                if (answer.ok) {
                    window.location.reload();
                }
            });
        });
    };

    immediateSelect('locale');
    immediateSelect('update_channel');

    /* 固定链接:预设选中 → 写进结构输入框 → 示例现算;手改结构框则反推预设(《76》§12.5) */
    (function () {
        var preset = document.querySelector('[data-permalink-preset]');
        var input = document.querySelector('[data-permalink-input]');
        var sample = document.querySelector('[data-permalink-sample]');

        if (preset === null || input === null || sample === null) {
            return;
        }

        // 示例里每段占位符换成什么样,跟服务端 permalinkSample() 对齐
        var SAMPLE = {
            '%postname%': 'hello-world',
            '%post_id%': '123',
            '%category%': 'uncategorized',
            '%year%': '2026',
            '%month%': '10',
            '%day%': '06',
            '%type%': 'post'
        };

        var render = function () {
            var path = input.value;
            var base = sample.getAttribute('data-permalink-base') || '';

            Object.keys(SAMPLE).forEach(function (key) {
                path = path.split(key).join(SAMPLE[key]);
            });

            sample.value = path === '' ? '' : base + (path.charAt(0) === '/' || path.charAt(0) === '?' ? path : '/' + path);
        };

        preset.addEventListener('change', function () {
            if (preset.value !== 'custom') {
                input.value = preset.value;
            }

            render();
        });

        input.addEventListener('input', function () {
            var matched = false;

            [].forEach.call(preset.options, function (option) {
                if (option.value !== 'custom' && option.value === input.value) {
                    matched = true;
                }
            });

            preset.value = matched ? input.value : 'custom';
            render();
        });

        render();
    })();

    /* 阅读组:首页显示选「静态页面」才露出选页下拉(《76》§12.2) */
    (function () {
        var select = document.querySelector('[data-front-page]');
        var picker = document.querySelector('[data-front-page-picker]');

        if (select === null || picker === null) {
            return;
        }

        select.addEventListener('change', function () {
            picker.hidden = select.value !== 'page';
        });
    })();

    /* 讨论组:头像来源选「自定义头像源」才露出地址输入(《76》§12.4) */
    (function () {
        var select = document.querySelector('[data-avatar-source]');
        var custom = document.querySelector('[data-avatar-custom]');

        if (select === null || custom === null) {
            return;
        }

        select.addEventListener('change', function () {
            custom.hidden = select.value !== 'custom';
        });
    })();

    /* 重置:只把当前分组换回默认再画一遍,不落库,故带 ?reset=1 走一次 GET。
       取消 / 点遮罩 / Esc 都收框,文案与《80》删除框同口径(《76》§12.6) */
    (function () {
        var button = document.querySelector('[data-reset]');

        if (button === null) {
            return;
        }

        var scrim = null;

        var close = function () {
            if (scrim === null) {
                return;
            }

            scrim.classList.remove('open');

            var box = scrim;
            scrim = null;
            window.setTimeout(function () {
                if (box.parentNode !== null) {
                    box.parentNode.removeChild(box);
                }
            }, 280);
        };

        button.addEventListener('click', function () {
            if (scrim !== null) {
                return;
            }

            var active = document.querySelector('.sec-tabs a.active');
            var label = active !== null ? active.textContent : '';

            scrim = document.createElement('div');
            scrim.className = 'scrim';
            scrim.innerHTML =
                '<div class="dlg" role="dialog" aria-modal="true">' +
                    '<div class="tag"></div>' +
                    '<h2></h2>' +
                    '<p class="tx2"></p>' +
                    '<p class="warn"></p>' +
                    '<div class="foot">' +
                        '<button class="btn btn-ghost" type="button" data-cancel></button>' +
                        '<button class="btn btn-danger" type="button" data-confirm></button>' +
                    '</div>' +
                '</div>';

            scrim.querySelector('.tag').textContent = molinT('危险操作');
            scrim.querySelector('h2').textContent = molinT('恢复默认设置？');
            scrim.querySelector('.tx2').textContent = molinTf('只把当前分组「%s」里的字段恢复成默认值，其他分组一概不动。', label);
            scrim.querySelector('.warn').textContent = molinT('未保存的改动会丢失，操作不可撤销。');
            scrim.querySelector('[data-cancel]').textContent = molinT('取消');
            scrim.querySelector('[data-confirm]').textContent = molinT('恢复默认');

            document.body.appendChild(scrim);
            window.requestAnimationFrame(function () {
                if (scrim !== null) {
                    scrim.classList.add('open');
                }
            });

            scrim.querySelector('[data-cancel]').addEventListener('click', close);

            scrim.querySelector('[data-confirm]').addEventListener('click', function () {
                var url = form.getAttribute('action') || window.location.pathname;

                window.location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + 'reset=1';
            });

            scrim.addEventListener('click', function (event) {
                if (event.target === scrim) {
                    close();
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });
    })();

    /* 清理缓存:POST /admin/cache/clear,成不成各给一句浮窗(《76》§12.7) */
    (function () {
        var button = document.querySelector('[data-clear-cache]');

        if (button === null || !window.fetch) {
            return;
        }

        button.addEventListener('click', function () {
            var url = button.getAttribute('data-url') || '';
            var body = new FormData();

            body.append(csrfField, csrfToken);
            button.disabled = true;

            fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (answer) {
                if (!answer.ok) {
                    throw new Error('cache');
                }

                molinToast(molinT('缓存已清理'));
            }).catch(function () {
                molinToast(molinT('没清成功，再试一次。'), 'err');
            }).then(function () {
                button.disabled = false;
            });
        });
    })();

    /* 检查更新:清单比对在「更新」页渲染时做,这里就把人送过去 */
    (function () {
        var button = document.querySelector('[data-check-update]');

        if (button === null) {
            return;
        }

        button.addEventListener('click', function () {
            window.location.href = button.getAttribute('data-url') || '';
        });
    })();

    /* 单选一改就提交(表单带 data-auto-submit):更新通道这类"选了立刻生效"的控件用它,
       比另加一颗按钮更贴基准图 */
    (function () {
        [].forEach.call(document.querySelectorAll('form[data-auto-submit]'), function (form) {
            [].forEach.call(form.querySelectorAll('input[type=radio]'), function (radio) {
                radio.addEventListener('change', function () {
                    if (radio.checked) {
                        form.submit();
                    }
                });
            });
        });
    })();

})();

/* ↑ 设置页增强到此为止:它一进页面就找 #settings-form,找不到整体 return。
   下面这块跑在「更新」页,只能放顶层 —— 2026-10-23 它曾被挪进去,确认框因此从来没挂上。 */

/* 升级进度:提交不跳页,边上按 700ms 轮进度 —— 九步与进度条当场动起来。
   跑完照旧跳回服务端渲染的那一屏(成不成由 PRG 后那条浮窗讲真话)。
   取消按钮自带 name=do value=cancel:它只落一个标志,走普通提交别拦。 */
(function () {
    var form = document.getElementById('update-run');

    if (form === null || !form.hasAttribute('data-upd-job')) {
        return;
    }

    var job = form.getAttribute('data-upd-job') || 'update';
    var url = form.getAttribute('data-upd-url') || '';
    var block = document.querySelector('.blk-prog');
    var fill = block === null ? null : block.querySelector('.bar i');
    var sub = block === null ? null : block.querySelector('.sub');
    var steps = block === null ? [] : [].slice.call(block.querySelectorAll('.stp'));
    var timer = null;
    var landed = '';

    var paint = function (data) {
        var percent = Math.max(0, Math.min(100, parseInt(data.percent, 10) || 0));
        var at = parseInt(data.done, 10) || 0;

        if (fill !== null) {
            fill.style.width = percent + '%';
        }

        if (sub !== null && (data.label || data.phase)) {
            sub.textContent = data.label || data.phase;
        }

        steps.forEach(function (step, index) {
            var no = index + 1;
            var state = no < at ? 'done' : (no === at ? 'cur' : 'todo');
            var label = state === 'done' ? '完成' : (state === 'cur' ? '进行中' : '待执行');
            var st = null;

            step.className = 'stp ' + state;
            st = step.querySelector('.st');

            if (st !== null) {
                st.textContent = molinT(label);
            }
        });
    };

    var stop = function () {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    };

    var poll = function () {
        var xhr = new XMLHttpRequest();

        xhr.open('GET', url, true);
        xhr.addEventListener('load', function () {
            var data = null;

            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                return;
            }

            if (data === null || data.job !== job) {
                return;
            }

            paint(data);

            if (data.running === true) {
                return;
            }

            stop();
            window.location.href = landed || window.location.href;
        });
        xhr.send();
    };

    form.addEventListener('submit', function (event) {
        var submitter = event.submitter || null;

        if (submitter !== null && submitter.value === 'cancel') {
            return;
        }

        /* 备份默认不做(2026-10-13 作者定):没勾就在提交前再问一次 ——
           点了确定才把 ack 置 1。服务端会再校验一次,绕过这一页也拦得住。
           勾选框桌面/移动各摆一份,所以按"有没有任一份勾上"判。 */
        var boxes = document.querySelectorAll('input[name="do_backup"]');
        var wantBackup = false;

        Array.prototype.forEach.call(boxes, function (item) {
            if (item.checked) {
                wantBackup = true;
            }
        });

        if (!wantBackup) {
            var go = window.confirm(molinT('这次不做备份。万一升级中途失败：程序会把文件退回原样，但数据库无法自动还原，只能靠你已有的备份或手工处理。确定开始升级吗？'));

            if (!go) {
                event.preventDefault();

                return;
            }

            var ack = form.querySelector('[data-upd-ack]');

            if (ack !== null) {
                ack.value = '1';
            }
        }

        event.preventDefault();
        stop();
        timer = window.setInterval(poll, 700);

        var xhr = new XMLHttpRequest();

        xhr.open('POST', form.getAttribute('action'));
        xhr.addEventListener('load', function () {
            if (xhr.status >= 200 && xhr.status < 400) {
                landed = xhr.responseURL || '';
                poll();

                return;
            }

            stop();
            window.location.reload();
        });
        xhr.send(new FormData(form));
    });
})();

/*
 * 编辑页自定义字段与控制增强(《34A》§4 控件映射 / §5 落位)。全是"增强":
 * 删掉这一层,字段照旧能填能存(文本 / 下拉 / 数值原生可交表),只是抽屉、颜色滑块联动、
 * 加行删行、选图回填这些便捷动作会退回朴素行为。
 */
(function () {
    'use strict';

    /* 面板抽屉(panel 落位):点按钮开,Esc / 点遮罩 / 点 × 关 */
    (function () {
        var tog = document.getElementById('ed-fields-tog');
        var drawer = document.getElementById('ed-fields-drawer');

        if (tog === null || drawer === null) {
            return;
        }

        var scrim = document.getElementById('ed-fields-scrim');

        var close = function () {
            drawer.classList.remove('open');

            if (scrim !== null) { scrim.classList.remove('open'); }

            tog.setAttribute('aria-expanded', 'false');
            window.setTimeout(function () {
                drawer.hidden = true;

                if (scrim !== null) { scrim.hidden = true; }
            }, 240);
        };

        var open = function () {
            drawer.hidden = false;

            if (scrim !== null) { scrim.hidden = false; }

            window.requestAnimationFrame(function () {
                drawer.classList.add('open');

                if (scrim !== null) { scrim.classList.add('open'); }
            });
            tog.setAttribute('aria-expanded', 'true');
        };

        tog.addEventListener('click', open);

        var x = document.getElementById('ed-fields-close');

        if (x !== null) { x.addEventListener('click', close); }
        if (scrim !== null) { scrim.addEventListener('click', close); }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !drawer.hidden) { close(); }
        });
    })();

    /* 颜色:色块与文本框双向同步(文本框才是提交值,色块只是个入口) */
    [].forEach.call(document.querySelectorAll('.page-editor .color'), function (box) {
        var sw = box.querySelector('input[type=color]');
        var tx = box.querySelector('input[type=text]');

        if (sw === null || tx === null) { return; }

        var hex = /^#[0-9a-fA-F]{6}$/;

        sw.addEventListener('input', function () { tx.value = sw.value; });
        tx.addEventListener('input', function () { if (hex.test(tx.value)) { sw.value = tx.value; } });
    });

    /* 滑块:读数跟着走 */
    [].forEach.call(document.querySelectorAll('.page-editor input[data-range]'), function (range) {
        range.addEventListener('input', function () {
            var wrap = range.closest('.rangew');
            var out = wrap === null ? null : wrap.querySelector('.rv');

            if (out !== null) { out.textContent = range.value; }
        });
    });

    /* 图片字段「清除」:值归 0,预览回空 */
    [].forEach.call(document.querySelectorAll('.page-editor [data-imgclear]'), function (button) {
        button.addEventListener('click', function () {
            var box = document.getElementById(button.getAttribute('data-imgclear'));

            if (box !== null) { box.value = '0'; }

            var holder = button.closest('.imgf');

            if (holder !== null) {
                var pv = holder.querySelector('.pv');
                var fn = holder.querySelector('.fn');

                if (pv !== null) { pv.textContent = ''; }
                if (fn !== null) { fn.textContent = molinT('未选择图片'); }
            }
        });
    });

    /* 分组 / 中继器:子控件值序列化成 JSON,写回隐藏的 meta_value[] */
    var readValue = function (el) {
        if (el.type === 'checkbox') { return el.checked ? '1' : '0'; }

        return el.value;
    };

    var renumber = function (box) {
        [].forEach.call(box.querySelectorAll('.cfrow .idx'), function (idx, i) {
            idx.textContent = String(i + 1);
        });
    };

    var syncGroup = function (box) {
        var single = box.getAttribute('data-cfj') === 'group';
        var out = [];

        [].forEach.call(box.querySelectorAll('.cfrow'), function (row) {
            var item = {};

            [].forEach.call(row.querySelectorAll('[data-subkey]'), function (el) {
                item[el.getAttribute('data-subkey')] = readValue(el);
            });
            out.push(item);
        });

        var hidden = box.querySelector('input[type=hidden][name="meta_value[]"]');

        if (hidden !== null) {
            hidden.value = JSON.stringify(single ? (out[0] || {}) : out);
        }
    };

    var makeSub = function (sub) {
        var kind = sub.control === 'textarea' ? 'textarea' : (sub.control === 'select' ? 'select' : 'input');
        var el = document.createElement(kind);
        el.setAttribute('data-subkey', sub.key);

        if (kind === 'select') {
            el.className = 'mini';
            (sub.options || []).forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                el.appendChild(opt);
            });
        } else if (sub.control === 'bool') {
            el.type = 'checkbox';
            el.value = '1';
        } else {
            el.className = 'mini-in';
            el.type = sub.control === 'number' || sub.control === 'image' ? 'number' : 'text';
        }

        return el;
    };

    [].forEach.call(document.querySelectorAll('.page-editor .cfj'), function (box) {
        var subs = [];

        try { subs = JSON.parse(box.getAttribute('data-sub') || '[]'); } catch (e) { subs = []; }

        box.addEventListener('input', function () { syncGroup(box); });
        box.addEventListener('change', function () { syncGroup(box); });
        box.addEventListener('click', function (event) {
            var add = event.target.closest('[data-cfadd]');
            var del = event.target.closest('[data-cfdel]');

            if (del !== null) {
                var row = del.closest('.cfrow');

                if (row !== null && row.parentNode !== null) { row.parentNode.removeChild(row); }

                renumber(box);
                syncGroup(box);

                return;
            }

            if (add === null) { return; }

            var fresh = document.createElement('div');
            fresh.className = 'cfrow';
            var idx = document.createElement('span');
            idx.className = 'idx';
            fresh.appendChild(idx);

            subs.forEach(function (sub) {
                var cell = document.createElement('span');
                cell.className = 'cfsub';
                cell.appendChild(makeSub(sub));
                fresh.appendChild(cell);
            });

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'lnk';
            remove.setAttribute('data-cfdel', '');
            remove.textContent = molinT('删除');
            fresh.appendChild(remove);

            var rows = box.querySelector('.cfrows');

            if (rows !== null) { rows.appendChild(fresh); }

            renumber(box);
            syncGroup(box);

            var first = fresh.querySelector('input, select, textarea');

            if (first !== null) { first.focus(); }
        });

        syncGroup(box);
    });

    /* 图片字段的选图:meta 模式选择器里点一张 → 写回隐藏输入与预览,收框不导航 */
    [].forEach.call(document.querySelectorAll('.dlg .grid .cell[data-meta]'), function (cell) {
        cell.addEventListener('click', function () {
            var dlg = cell.closest('.dlg');
            var key = dlg === null ? '' : (dlg.getAttribute('data-meta-key') || '');

            if (key === '') { return; }

            var hidden = document.getElementById('cf-' + key);

            if (hidden !== null) { hidden.value = cell.getAttribute('data-meta'); }

            var pv = document.getElementById('imgpv-' + key);
            var thumb = cell.getAttribute('data-mthumb') || '';

            if (pv !== null) {
                pv.textContent = '';

                if (thumb !== '') {
                    var img = document.createElement('img');
                    img.src = thumb;
                    img.alt = '';
                    pv.appendChild(img);
                }
            }

            var holder = hidden === null ? null : hidden.closest('.imgf');
            var nameNode = holder === null ? null : holder.querySelector('.fn');

            if (nameNode !== null) { nameNode.textContent = cell.getAttribute('data-mname') || ''; }
            if (dlg !== null) { dlg.hidden = true; }

            var scrim = document.querySelector('.scrim');

            if (scrim !== null) { scrim.classList.remove('open'); }

            [].forEach.call(document.querySelectorAll('.content, .sidebar'), function (node) {
                node.removeAttribute('inert');
            });

            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname);
            }
        });
    });
})();

/*
 * 挂件屏(《108》§6):按住左侧三条短横拖动 —— 同区里改顺序,拖到另一区就搬过去。
 * 鼠标 / 触屏 / 触控笔走同一条 pointer 事件。拖完把排布写回表单里那组隐藏输入(保存时唯一的数据通道),
 * 顺手改对每行「移除」的区与序号、以及两处副本(桌面 / 移动各一份结构)的计数。
 */
(function () {
    var form = document.getElementById('widgets-form');

    if (form === null) {
        return;
    }

    var KEYS = ['type', 'title', 'sub', 'from'];
    var copies = document.querySelectorAll('.page-widgets .split');
    var drag = null;

    var sync = function (split) {
        [].forEach.call(form.querySelectorAll('input[name^="widgets["]'), function (el) {
            el.parentNode.removeChild(el);
        });

        var anchor = form.firstChild;
        var counts = {};

        [].forEach.call(split.querySelectorAll('.zone'), function (zone) {
            var area = zone.getAttribute('data-area');

            counts[area] = 0;

            [].forEach.call(zone.querySelectorAll('.wrow'), function (row) {
                var i = counts[area];

                counts[area] += 1;

                KEYS.forEach(function (key) {
                    var input = document.createElement('input');

                    input.type = 'hidden';
                    input.name = 'widgets[' + area + '][' + i + '][' + key + ']';
                    input.value = row.getAttribute('data-' + key) || '';
                    form.insertBefore(input, anchor);
                });
            });
        });

        [].forEach.call(copies, function (copy) {
            [].forEach.call(copy.querySelectorAll('.zone'), function (zone) {
                var area = zone.getAttribute('data-area');
                var n = 0;
                var cnt = zone.querySelector('.cnt b');

                [].forEach.call(zone.querySelectorAll('.wrow'), function (row) {
                    var rm = row.querySelector('.rm');

                    if (rm !== null) {
                        rm.setAttribute('value', 'remove:' + area + ':' + n);
                    }

                    n += 1;
                });

                if (cnt !== null && counts[area] !== undefined) {
                    cnt.textContent = String(counts[area]);
                }
            });
        });
    };

    /* 落点只看指针的纵向位置:先挑最近的区,再挑插到哪一行之前(没有就落到虚线框前,即该区末尾) */
    var place = function (ev) {
        var zone = null;
        var best = Infinity;

        [].forEach.call(drag.split.querySelectorAll('.zone'), function (z) {
            var r = z.getBoundingClientRect();
            var d = 0;

            if (ev.clientY < r.top) {
                d = r.top - ev.clientY;
            } else if (ev.clientY > r.bottom) {
                d = ev.clientY - r.bottom;
            }

            if (d < best) {
                best = d;
                zone = z;
            }
        });

        if (zone === null) {
            return;
        }

        var before = null;

        [].forEach.call(zone.querySelectorAll('.wrow'), function (row) {
            var r;

            if (before !== null || row === drag.row) {
                return;
            }

            r = row.getBoundingClientRect();

            if (ev.clientY < r.top + r.height / 2) {
                before = row;
            }
        });

        var at = before !== null ? before : zone.querySelector('.drop');

        if (at === null || (drag.row.parentNode === zone && drag.row.nextElementSibling === at)) {
            return;
        }

        zone.insertBefore(drag.row, at);
    };

    var stop = function (ev) {
        var split;
        var moved;

        if (drag === null || ev.pointerId !== drag.id) {
            return;
        }

        drag.row.classList.remove('dragging');

        split = drag.split;
        moved = drag.moved;
        drag = null;

        if (moved) {
            sync(split);
        }
    };

    /* 键盘:把手聚焦后按上下键挪一格(键盘用户走不了拖动这条路;把手在模板里是 tabindex=0 的按钮) */
    var shift = function (row, dir) {
        var zone = row.parentNode;
        var sibs = [].filter.call(zone.querySelectorAll('.wrow'), function () {
            return true;
        });
        var at = sibs.indexOf(row);
        var to = at + dir;

        if (at < 0 || to < 0 || to >= sibs.length) {
            return false;
        }

        zone.insertBefore(row, dir < 0 ? sibs[to] : sibs[to].nextElementSibling);

        return true;
    };

    var keyMove = function (handle, dir) {
        var row = handle.closest('.wrow');

        if (row === null || !shift(row, dir)) {
            return false;
        }

        sync(row.closest('.split'));
        handle.focus();

        return true;
    };

    [].forEach.call(copies, function (split) {
        split.addEventListener('keydown', function (ev) {
            var handle;

            if (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown') {
                return;
            }

            handle = ev.target.closest('.handle');

            if (handle !== null && keyMove(handle, ev.key === 'ArrowUp' ? -1 : 1)) {
                ev.preventDefault();
            }
        });
    });

    [].forEach.call(copies, function (split) {
        split.addEventListener('pointerdown', function (ev) {
            var handle = ev.target.closest('.handle');
            var row = handle === null ? null : handle.closest('.wrow');

            if (row === null || (ev.pointerType === 'mouse' && ev.button !== 0)) {
                return;
            }

            drag = {row: row, split: split, id: ev.pointerId, moved: false};
            row.classList.add('dragging');
            ev.preventDefault();
        });
    });

    document.addEventListener('pointermove', function (ev) {
        if (drag === null || ev.pointerId !== drag.id) {
            return;
        }

        drag.moved = true;
        place(ev);
    });

    document.addEventListener('pointerup', stop);
    document.addEventListener('pointercancel', stop);
})();

/*
 * 菜单编辑器(《107》):按住左侧三条短横拖动改顺序,拖到某一项的下半截就收作它的子项(最多两级)。
 * 「移除」在本地删掉这一行(父项连它那一支的子项一起删)。每次改完按 DOM 顺序重排字段名 ——
 * 服务端是照 items[i] / items[i][children][j] 前缀重建树的,名字错位就会把整棵树存歪。
 * 左栏分组标题的展开 / 收起只动 UI 态,不动数据。
 */
(function () {
    var form = document.getElementById('menus-form');

    if (form === null) {
        return;
    }

    var drag = null;

    /* 子项永远紧跟父项(靠 .child 认),所以"一项 + 它那一支"之后的下一行就是它的块尾 */
    var afterBlock = function (row) {
        var next = row.nextElementSibling;

        while (next !== null && next.classList.contains('child')) {
            next = next.nextElementSibling;
        }

        return next;
    };

    var hasChildren = function (row) {
        return !row.classList.contains('child')
            && row.nextElementSibling !== null
            && row.nextElementSibling.classList.contains('child');
    };

    var rename = function (row, prefix) {
        [].forEach.call(row.querySelectorAll('input, select'), function (el) {
            var name = el.getAttribute('name') || '';
            var key = name.slice(name.lastIndexOf('[') + 1, -1);

            if (key !== '') {
                el.setAttribute('name', prefix + '[' + key + ']');
            }
        });
    };

    var renumber = function () {
        var parent = -1;
        var child = 0;

        [].forEach.call(form.querySelectorAll('.mrow'), function (row) {
            if (!row.classList.contains('child')) {
                parent += 1;
                child = 0;
                rename(row, 'items[' + parent + ']');

                return;
            }

            rename(row, 'items[' + parent + '][children][' + child + ']');
            child += 1;
        });
    };

    var place = function (ev) {
        var list = [].filter.call(form.querySelectorAll('.mrow'), function (row) {
            return row !== drag.row;
        });
        var hit = null;
        var i;

        for (i = 0; i < list.length; i += 1) {
            if (ev.clientY < list[i].getBoundingClientRect().bottom) {
                hit = list[i];

                break;
            }
        }

        var asChild = false;
        var anchor = null;

        if (hit !== null) {
            var rect = hit.getBoundingClientRect();

            if (ev.clientY <= rect.top + rect.height / 2) {
                asChild = hit.classList.contains('child');
                anchor = hit;
            } else if (hit.classList.contains('child') || hasChildren(drag.row)) {
                asChild = hit.classList.contains('child');
                anchor = afterBlock(hit);
            } else {
                asChild = true;
                anchor = afterBlock(hit);
            }
        }

        // 带子项的行只能留在顶级:真落到子项堆里就退回它父项前面,免得存出第三级
        if (asChild && hasChildren(drag.row)) {
            asChild = false;
            anchor = hit;

            while (anchor !== null && anchor.classList.contains('child')) {
                anchor = anchor.previousElementSibling;
            }
        }

        if (anchor === drag.row) {
            return;
        }

        if (asChild) {
            drag.row.classList.add('child');
        } else {
            drag.row.classList.remove('child');
        }

        form.insertBefore(drag.row, anchor);
    };

    var stop = function (ev) {
        var moved;

        if (drag === null || ev.pointerId !== drag.id) {
            return;
        }

        drag.row.classList.remove('dragging');

        moved = drag.moved;
        drag = null;

        if (moved) {
            renumber();
        }
    };

    form.addEventListener('pointerdown', function (ev) {
        var handle = ev.target.closest('.handle');
        var row = handle === null ? null : handle.closest('.mrow');

        if (row === null || (ev.pointerType === 'mouse' && ev.button !== 0)) {
            return;
        }

        drag = {row: row, id: ev.pointerId, moved: false};
        row.classList.add('dragging');
        ev.preventDefault();
    });

    document.addEventListener('pointermove', function (ev) {
        if (drag === null || ev.pointerId !== drag.id) {
            return;
        }

        drag.moved = true;
        place(ev);
    });

    document.addEventListener('pointerup', stop);
    document.addEventListener('pointercancel', stop);

    form.addEventListener('click', function (ev) {
        var rm = ev.target.closest('.rm');
        var row = rm === null ? null : rm.closest('.mrow');
        var next;

        if (row === null) {
            return;
        }

        if (!row.classList.contains('child')) {
            next = row.nextElementSibling;

            while (next !== null && next.classList.contains('child')) {
                var kill = next;

                next = next.nextElementSibling;
                kill.remove();
            }
        }

        row.remove();
        renumber();
    });

    /* 新加的一行:照 <template> 克隆,填名称与地址;字段名交给 renumber() 统一重排 */
    var addRows = function (items) {
        var tpl = document.getElementById('menu-row-tpl');
        var first = null;

        if (tpl === null || items.length === 0) {
            return;
        }

        items.forEach(function (item) {
            var row = tpl.content.firstElementChild.cloneNode(true);

            row.querySelector('.nm').value = item.label;
            row.querySelector('.url').value = item.url;
            form.appendChild(row);

            if (first === null) {
                first = row;
            }
        });

        renumber();

        if (first !== null) {
            first.querySelector('.handle').focus();
        }
    };

    // 左栏两枚「加入菜单」:分组里那枚收自定义链接那两个输入框,页脚那枚收勾中的项
    document.addEventListener('click', function (ev) {
        var link = ev.target.closest('[data-add-link]');
        var picked = ev.target.closest('[data-add-picked]');
        var box;
        var label;
        var url;
        var items;

        if (link !== null) {
            box = link.closest('.linkform');
            label = box === null ? null : box.querySelector('.lnk-label');
            url = box === null ? null : box.querySelector('.lnk-url');

            if (label === null || label.value.trim() === '') {
                molinToast(molinT('自定义链接要先填链接文字。'), 'err');

                return;
            }

            addRows([{label: label.value.trim(), url: url === null ? '' : url.value.trim()}]);
            label.value = '';

            if (url !== null) {
                url.value = '';
            }

            return;
        }

        if (picked === null) {
            return;
        }

        items = [];

        [].forEach.call(document.querySelectorAll('.page-menus .pitem'), function (row) {
            var cb = row.querySelector('.cb');
            var name;

            if (cb === null || !cb.checked) {
                return;
            }

            name = row.querySelector('span');
            items.push({
                label: name === null ? '' : name.textContent.trim(),
                url: (row.querySelector('.addr') === null ? '' : row.querySelector('.addr').textContent).trim()
            });
            cb.checked = false;
        });

        if (items.length === 0) {
            molinToast(molinT('还没勾选任何项目。'), 'err');

            return;
        }

        addRows(items);
    });

    /* 这一行所在"支"的父行:子项往回走到它那一支的父项;父项返回自己 */
    var blockHead = function (row) {
        var head = row;

        while (head.classList.contains('child')) {
            head = head.previousElementSibling;
        }

        return head;
    };

    /* 上一个"块头"(跳过它自己的子项) */
    var previousBlockHead = function (row) {
        var prev = row.previousElementSibling;

        while (prev !== null && prev.classList.contains('child')) {
            prev = prev.previousElementSibling;
        }

        return prev;
    };

    /* 上下键:整块地挪 —— 父项连它那一支一起跳过一格,子项只在同一支里换位(要跨层得拖动) */
    var shift = function (row, dir) {
        var block = [row];
        var next = row.nextElementSibling;
        var head;
        var anchor;

        while (next !== null && next.classList.contains('child')) {
            block.push(next);
            next = next.nextElementSibling;
        }

        if (row.classList.contains('child')) {
            var sibs = [].filter.call(form.querySelectorAll('.mrow.child'), function (r) {
                return blockHead(r) === blockHead(row);
            });
            var at = sibs.indexOf(row);
            var to = at + dir;

            if (at < 0 || to < 0 || to >= sibs.length) {
                return false;
            }

            if (dir < 0) {
                form.insertBefore(row, sibs[to]);
            } else {
                form.insertBefore(row, sibs[to].nextElementSibling);
            }

            return true;
        }

        head = dir < 0 ? previousBlockHead(row) : afterBlock(row);

        if (head === null) {
            return false;
        }

        anchor = dir < 0 ? head : afterBlock(head);

        block.forEach(function (node) {
            form.insertBefore(node, anchor);
        });

        return true;
    };

    form.addEventListener('keydown', function (ev) {
        var handle;
        var row;

        if (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown') {
            return;
        }

        handle = ev.target.closest('.handle');
        row = handle === null ? null : handle.closest('.mrow');

        if (row !== null && shift(row, ev.key === 'ArrowUp' ? -1 : 1)) {
            renumber();
            handle.focus();
            ev.preventDefault();
        }
    });

    [].forEach.call(document.querySelectorAll('.page-menus .phead'), function (head) {
        head.addEventListener('click', function () {
            var group = head.closest('.pgroup');
            var closed = group.classList.toggle('closed');

            head.setAttribute('aria-expanded', closed ? 'false' : 'true');
        });
    });
})();
