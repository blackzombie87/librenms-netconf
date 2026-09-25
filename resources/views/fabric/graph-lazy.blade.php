{{-- promote(root): copy `data-src` to `src` inside ONE root, and build the hover from the
     period URLs beside it. Nothing on a page fetches a graph until something calls this.

     The server never sets a `src`. `Url::graphPopup()` emits one immediately and four more
     inside its overlib string, and a closed <details> or a hidden panel is still in the first
     response -- 46 ESI segments would be well past a hundred graph requests on a page where
     nobody has asked about traffic yet. --}}
@once
    @push('scripts')
    <script type="text/javascript">
    (function () {
        window.netconfPromote = function (root) {
            if (!root) { return; }
            Array.prototype.forEach.call(root.querySelectorAll('img[data-src]'), function (img) {
                img.src = img.dataset.src;
                delete img.dataset.src;
                var urls = [];
                try { urls = JSON.parse(img.dataset.popup || '[]'); } catch (e) {}
                if (!urls.length || typeof overlib !== 'function') { return; }
                var html = '<div style="display:grid;grid-template-columns:repeat(2,max-content);">' +
                    urls.map(function (u) { return '<img src="' + u + '" style="border:0;">'; }).join('') + '</div>';
                img.onmouseover = function () { overlib(html, CAPTION, img.alt, FGCOLOR, '#e5e5e5'); };
                img.onmouseout = function () { return nd(); };
            });
        };
        document.addEventListener('DOMContentLoaded', function () {
            Array.prototype.forEach.call(document.querySelectorAll('details.esi-traffic'), function (el) {
                el.addEventListener('toggle', function (ev) { if (ev.target.open) { window.netconfPromote(ev.target); } });
            });
            // a trace result is the answer to a question that was just asked, so its handful
            // of per-hop graphs are wanted; an overview's 46 ESI panels are not
            window.netconfPromote(document.getElementById('netconf-trace-result'));
            var focus = new URL(window.location.href).searchParams.get('focus') || '';
            if (focus.indexOf('esi:') !== 0) { return; }   // member: and edge: panels have no graphs
            window.netconfPromote(document.querySelector('#eagle-inspector [data-focus="' + (window.CSS && CSS.escape ? CSS.escape(focus) : focus) + '"]'));
        });
    })();
    </script>
    @endpush
@endonce
