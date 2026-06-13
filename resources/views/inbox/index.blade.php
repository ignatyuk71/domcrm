<x-app-layout>
    <style>
        .ib-wrap { height: calc(100vh - 64px); overflow: hidden; background: #f3f4f8; font-size: 14px; }
        .ib-col { display: flex; flex-direction: column; min-height: 0; }
        .ib-list  { width: 25%; min-width: 280px; background: #fff; border-right: 1px solid #ecedf1; }
        .ib-thread{ width: 50%; background: #f3f4f8; }
        .ib-info  { width: 25%; min-width: 270px; background: #fff; border-left: 1px solid #ecedf1; }
        @media (max-width: 1300px) { .ib-info { display: none !important; } .ib-list { width: 34%; } .ib-thread { width: 66%; } }
        @media (max-width: 900px)  { .ib-list { width: 100%; } .ib-thread { display: none !important; } }

        .ib-head { padding: 14px 16px 10px; border-bottom: 1px solid #f0f1f4; }
        .ib-title { font-weight: 800; font-size: 1.05rem; color: #0f172a; letter-spacing: -.3px; }
        .ib-iconbtn { width: 32px; height: 32px; border-radius: 8px; border: 1px solid #ecedf1; background: #fff; color: #64748b; display: inline-flex; align-items: center; justify-content: center; transition: .15s; }
        .ib-iconbtn:hover { background: #f5f6ff; color: #4f46e5; border-color: #dfe1f5; }

        .ib-search { position: relative; padding: 0 14px 8px; }
        .ib-search input { width: 100%; border: 1px solid #ecedf1; background: #f6f7f9; border-radius: 9px; padding: 7px 12px 7px 34px; font-size: .85rem; outline: none; }
        .ib-search input:focus { background: #fff; border-color: #c7cbf0; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
        .ib-search .bi-search { position: absolute; left: 26px; top: 8px; color: #94a3b8; font-size: .85rem; }

        .ib-filters { display: flex; gap: 5px; padding: 0 14px 10px; }
        .ib-tabs { display: flex; gap: 2px; padding: 0 14px 8px; border-bottom: 1px solid #f0f1f4; }
        .ib-tab { flex: 1; border: none; background: transparent; padding: 8px 6px; font-size: .82rem; font-weight: 600; color: #64748b; border-bottom: 2px solid transparent; cursor: pointer; }
        .ib-tab.active { color: #4f46e5; border-bottom-color: #4f46e5; }
        .ib-tab-badge { background: #e11d48; color: #fff; border-radius: 999px; font-size: .64rem; padding: 1px 6px; margin-left: 3px; }
        .ib-cm { padding: 10px 14px; border-bottom: 1px solid #f5f6f8; }
        .ib-cm .hd { display: flex; align-items: center; gap: 8px; }
        .ib-cm .nm { font-weight: 600; font-size: .85rem; color: #1e293b; flex: 1; min-width: 0; }
        .ib-cm .tm { font-size: .68rem; color: #9aa3b2; white-space: nowrap; }
        .ib-cm .del { width: 24px; height: 24px; border: none; background: #f1f3f6; border-radius: 6px; color: #94a3b8; font-size: .72rem; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ib-cm .del:hover { background: #fde8e8; color: #dc3545; }
        .ib-cm .tx { font-size: .82rem; color: #334155; margin: 5px 0 0 0; }
        .ib-cm .post { display: flex; gap: 8px; align-items: center; background: #f6f7f9; border-radius: 9px; padding: 6px 8px; margin-top: 7px; }
        .ib-cm .post img { width: 38px; height: 38px; border-radius: 7px; object-fit: cover; flex-shrink: 0; }
        .ib-cm .post span { font-size: .72rem; color: #6b7280; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .ib-cm .actions { margin-top: 7px; display: flex; gap: 6px; align-items: center; }
        .ib-cm .dm-form { display: flex; gap: 6px; margin-top: 7px; }
        .ib-cm .dm-form input { flex: 1; border: 1px solid #dde0e6; border-radius: 9px; padding: 5px 10px; font-size: .82rem; outline: none; }
        .ib-cm-sent { font-size: .72rem; color: #16a34a; font-weight: 600; }
        .ib-chip { font-size: .76rem; padding: 4px 11px; border-radius: 18px; background: #f1f2f6; color: #64748b; border: none; cursor: pointer; transition: .15s; font-weight: 500; }
        .ib-chip:hover { background: #e9eaf0; }
        .ib-chip.active { background: #4f46e5; color: #fff; }

        .ib-convs { flex: 1; overflow-y: auto; }
        .ib-conv-more { display: flex; justify-content: center; padding: 12px 0 16px; color: #8a8d91; }
        .ib-st-badge { font-size: .64rem; font-weight: 700; padding: 2px 7px; border-radius: 999px; line-height: 1.25; white-space: nowrap; flex-shrink: 0; }
        .ib-conv { display: flex; gap: 10px; padding: 9px 14px; cursor: pointer; position: relative; transition: background .12s; }
        .ib-conv:hover { background: #f7f8fa; }
        .ib-conv.active { background: #f1f2ff; }
        .ib-conv.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: linear-gradient(#6366f1,#8b5cf6); }
        .ib-conv .meta { flex: 1; min-width: 0; }
        .ib-conv .nm { font-weight: 600; color: #1e293b; font-size: .86rem; }
        .ib-conv.unread .nm { font-weight: 800; color: #0f172a; }
        .ib-conv .pv { color: #6b7280; font-size: .8rem; line-height: 1.25; }
        .ib-conv.unread .pv { color: #334155; font-weight: 500; }
        .ib-conv .store { color: #aab2c0; font-size: .68rem; }
        .ib-time { color: #9aa3b2; font-size: .7rem; white-space: nowrap; }

        .ib-av { position: relative; flex-shrink: 0; }
        .ib-av .circle { border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; background: linear-gradient(135deg,#818cf8,#a78bfa); overflow: hidden; }
        .ib-av .circle img { width: 100%; height: 100%; object-fit: cover; }
        .ib-av .ch { position: absolute; right: 3px; bottom: 3px; width: 13px; height: 13px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 16px; line-height: 1; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
        .ib-dot { width: 8px; height: 8px; border-radius: 50%; background: #2563eb; flex-shrink: 0; align-self: center; }

        .ib-thead { padding: 10px 18px; background: #fff; border-bottom: 1px solid #ecedf1; }
        .ib-th-name { font-weight: 700; font-size: 1.02rem; color: #050505; line-height: 1.25; }
        .ib-th-store { display: inline-flex; align-items: center; gap: 6px; margin-top: 3px; background: #f0f2f5; border-radius: 999px; padding: 2px 10px 2px 3px; font-size: .76rem; font-weight: 600; color: #050505; max-width: 100%; }
        .ib-th-store img { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; display: block; flex-shrink: 0; }
        .ib-th-store i { font-size: .85rem; color: #65676b; margin-left: 5px; }
        .ib-th-store span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ib-status-wrap { display: inline-flex; align-items: center; gap: 7px; background: #f0f2f5; border-radius: 999px; padding: 6px 12px 6px 12px; flex-shrink: 0; }
        .ib-status-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--st, #adb5bd); flex-shrink: 0; }
        .ib-status-sel { border: none; background: transparent; outline: none; font-size: .84rem; font-weight: 600; color: #1c1e21; cursor: pointer; max-width: 170px; }
        .ib-thbtn { width: 36px; height: 36px; border-radius: 50%; border: none; background: #f0f2f5; color: #1c1e21; display: inline-flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; transition: background .12s; }
        .ib-thbtn:hover { background: #e4e6eb; }
        .ib-thbtn:disabled { opacity: .6; }
        .ib-th-pop { position: absolute; top: 42px; right: 0; background: #fff; border: 1px solid #e6e8ee; border-radius: 12px; box-shadow: 0 14px 36px rgba(16,24,40,.16); z-index: 60; min-width: 200px; padding: 6px; }
        .ib-th-pop button { display: flex; align-items: center; width: 100%; border: none; background: transparent; padding: 9px 12px; border-radius: 8px; font-size: .88rem; text-align: left; }
        .ib-th-pop button:hover { background: #f0f2f5; }
        .ib-th-pop button.danger { color: #dc3545; }
        .ib-spin { animation: ibspin 1s linear infinite; }
        @keyframes ibspin { to { transform: rotate(360deg); } }
        .ib-msgs { flex: 1; overflow-y: auto; padding: 18px 22px; display: flex; flex-direction: column; gap: 2px; background: #fff; }
        .ib-row { display: flex; margin-bottom: 1px; }
        .ib-row.out { justify-content: flex-end; }
        .ib-bub { max-width: 64%; padding: 8px 12px; font-size: .94rem; line-height: 1.38; white-space: pre-wrap; word-break: break-word; }
        .ib-bub.in  { background: #f0f0f0; color: #050505; border-radius: 18px; }
        .ib-bub.out { background: #0084ff; color: #fff; border-radius: 18px; }
        .ib-bub.media { background: transparent; padding: 0; }
        .ib-bub img { max-width: 230px; border-radius: 16px; display: block; }
        .ib-time-mini { font-size: .68rem; color: #8a8d91; margin: 2px 6px 8px; }
        .ib-time-mini.out { text-align: right; }
        .ib-ctx { max-width: 64%; background: #f5f6f8; border-left: 3px solid #cdd2dc; border-radius: 10px; padding: 6px 10px; margin-bottom: 2px; font-size: .74rem; color: #65676b; display: flex; flex-direction: column; gap: 4px; }
        .ib-ctx img { max-width: 120px; max-height: 120px; border-radius: 8px; object-fit: cover; display: block; }

        .ib-composer { padding: 12px 16px 14px; background: #fff; border-top: 1px solid #ecedf1; position: relative; }
        .ib-box { display: flex; align-items: flex-start; gap: 12px; background: #fff; border: 1px solid #e3e6ea; border-radius: 16px; padding: 10px 14px; }
        .ib-box:focus-within { border-color: #cfd3da; }
        .ib-box-av { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #eef0f3; color: #9aa3af; font-size: 17px; margin-top: 2px; }
        .ib-box-av img { width: 100%; height: 100%; object-fit: cover; }
        .ib-box textarea { flex: 1; border: none; background: transparent; outline: none; font-size: 1rem; color: #1c1e21; padding: 7px 0; resize: none; min-height: 80px; max-height: 200px; overflow-y: auto; line-height: 1.4; font-family: inherit; }
        .ib-box textarea::placeholder { color: #8a8d91; }
        .ib-box-tools { display: flex; align-items: center; gap: 8px; flex-shrink: 0; align-self: flex-end; }
        .ib-tool { width: 34px; height: 34px; border-radius: 50%; border: none; background: transparent; color: #1c1e21; display: inline-flex; align-items: center; justify-content: center; font-size: 22px; cursor: pointer; padding: 0; transition: background .12s; }
        .ib-tool:hover { background: #f0f1f4; }
        .ib-send-ic { color: #0084ff; }
        .ib-send-ic:hover { background: #eaf3ff; color: #0084ff; }
        .ib-send-ic .spinner-border { width: 17px; height: 17px; border-width: 2px; }
        .ib-pop { position: absolute; bottom: calc(100% - 4px); right: 14px; left: auto; background: #fff; border: 1px solid #e6e8ee; border-radius: 13px; box-shadow: 0 14px 36px rgba(16,24,40,.16); z-index: 50; padding: 9px; }
        .ib-attach-preview { padding: 4px 6px 12px; display: flex; flex-wrap: wrap; gap: 12px; }
        .ib-attach-item { position: relative; width: 68px; height: 68px; background: #f5f6f8; border: 1px solid #e3e6ea; border-radius: 12px; }
        .ib-attach-item img { width: 100%; height: 100%; object-fit: cover; border-radius: 11px; display: block; }
        .ib-attach-file { width: 100%; height: 100%; border-radius: 11px; background: #e9ecf2; display: flex; align-items: center; justify-content: center; font-size: 26px; color: #6b7280; }
        .ib-attach-spin { position: absolute; inset: 0; border-radius: 11px; background: rgba(233,236,242,.78); display: flex; align-items: center; justify-content: center; color: #65676b; }
        .ib-attach-x { position: absolute; top: -7px; right: -7px; width: 22px; height: 22px; border-radius: 50%; border: 2px solid #fff; background: #1c1e21; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; line-height: 1; }
        .ib-gallery { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; width: 304px; max-height: 280px; overflow-y: auto; }
        .ib-gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid transparent; }
        .ib-gallery img:hover { border-color: #0084ff; }
        .ib-modal { position: fixed; inset: 0; background: rgba(15,18,30,.55); z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .ib-modal-card { background: #fff; border-radius: 16px; width: min(880px, 96vw); max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,.3); }
        .ib-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid #ecedf1; }
        .ib-modal-title { font-weight: 700; font-size: 1.02rem; }
        .ib-modal-close { border: none; background: transparent; font-size: 17px; color: #6b7280; cursor: pointer; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .ib-modal-close:hover { background: #f1f2f6; }
        .ib-modal-body { padding: 16px 18px; overflow-y: auto; }
        .ib-mgrid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .ib-mtile { position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden; cursor: pointer; border: 2px solid #eef0f3; background: #f7f8fa; }
        .ib-mtile img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .ib-mtile.sel { border-color: #0084ff; }
        .ib-mtile.sel::after { content: ''; position: absolute; inset: 0; background: rgba(0,132,255,.16); }
        .ib-mtile .num { position: absolute; top: 6px; right: 6px; min-width: 22px; height: 22px; padding: 0 6px; border-radius: 11px; background: #0084ff; color: #fff; font-size: .74rem; font-weight: 700; display: flex; align-items: center; justify-content: center; z-index: 1; }
        .ib-mtile:not(.sel) .num { display: none; }
        .ib-modal-foot { display: flex; align-items: center; justify-content: space-between; padding: 12px 18px; border-top: 1px solid #ecedf1; gap: 12px; }
        .ib-emoji-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; width: 280px; }
        .ib-emoji-grid button { border: none; background: transparent; font-size: 1.25rem; padding: 4px; border-radius: 7px; cursor: pointer; }
        .ib-emoji-grid button:hover { background: #f1f2f6; }
        .ib-tpl { width: 320px; max-height: 290px; overflow-y: auto; }
        .ib-tpl-item { padding: 8px 10px; border-radius: 8px; cursor: pointer; }
        .ib-tpl-item:hover { background: #f5f6ff; }
        .ib-tpl-item .tt { font-weight: 600; font-size: .82rem; color: #0f172a; }
        .ib-tpl-item .bd { font-size: .74rem; color: #94a3b8; }

        /* права панель — блоки як у FB */
        .ib-iblock { padding: 14px 16px; border-bottom: 1px solid #f0f1f4; }
        .ib-block-title { font-weight: 700; font-size: .9rem; color: #0f172a; margin-bottom: 8px; }
        .ib-mini-btn { width: 26px; height: 26px; border: none; background: #f0f2f5; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; color: #475467; flex-shrink: 0; }
        .ib-mini-btn:hover { background: #e4e6eb; }
        .ib-src { display: inline-flex; align-items: center; gap: 5px; font-size: .74rem; font-weight: 600; color: #475467; background: #f0f2f5; border-radius: 999px; padding: 3px 9px; }
        .ib-top { padding: 16px 16px 14px; border-bottom: 1px solid #f0f1f4; }
        .ib-client-box { background: linear-gradient(180deg, #f8fafc, #f1f5f9); border: 1px solid #e8ecf2; border-radius: 14px; padding: 12px; margin-top: 12px; text-align: left; }
        .ib-cb-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .ib-cb-title { font-weight: 700; font-size: .82rem; color: #0f172a; }
        .ib-cb-badge { font-size: .66rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
        .ib-cb-badge.ok { background: #e7f6ec; color: #2fb344; }
        .ib-cb-badge.no { background: #fff4e5; color: #d97706; }
        .ib-input-wrap { position: relative; margin-bottom: 8px; }
        .ib-input-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9aa3af; font-size: 13px; pointer-events: none; }
        .ib-input-wrap input { padding-left: 30px; background: #fff; }
        .ib-input-wrap input.bad { border-color: #dc3545; }
        .ib-ferr { font-size: .7rem; color: #dc3545; margin: -4px 0 7px 2px; }
        /* === Права панель у мові дизайну адмінки (clean-card) === */
        #info { background: #f8fafc; }
        .ib-panel { padding: 12px; display: flex; flex-direction: column; gap: 12px; }
        .clean-card-sm { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.02); }
        .ib-sec-title { font-size: .74rem; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: .04em; display: flex; align-items: center; }
        .text-purple { color: #8b5cf6 !important; }
        .form-label-custom { font-size: .66rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: .3rem; letter-spacing: .03em; display: block; }
        .custom-input { border-radius: 10px; border: 1px solid #e2e8f0; }
        .custom-input.has-icon { padding-left: 32px; }
        .custom-input.bad { border-color: #ef4444; }
        .input-group-custom { position: relative; }
        .input-icon-left { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; z-index: 5; font-size: .85rem; pointer-events: none; }
        .btn-action { width: 34px; height: 34px; border-radius: 10px; border: none; display: flex; align-items: center; justify-content: center; background: #f8f9fa; color: #64748b; flex-shrink: 0; transition: .2s; }
        .btn-action.edit:hover { background: #e0f2fe; color: #0ea5e9; }
        .btn-action.delete:hover { background: #fee2e2; color: #ef4444; }
        .btn-primary-gradient { background: linear-gradient(135deg, #6366f1, #4f46e5); border: none; color: #fff; border-radius: 10px; }
        .btn-primary-gradient:hover:not(:disabled) { box-shadow: 0 4px 12px rgba(79,70,229,.35); color: #fff; }
        .btn-white { background: #fff; }
        .icon-box { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; color: #64748b; flex-shrink: 0; font-size: 17px; transition: .2s; }
        .icon-box-active { background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; box-shadow: 0 2px 8px rgba(79,70,229,.3); }
        .payment-card { border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px; cursor: pointer; background: #fff; transition: all .2s; user-select: none; }
        .payment-card.selected { border-color: #4f46e5; box-shadow: 0 0 0 1px #4f46e5, 0 4px 12px rgba(79,70,229,.12); }
        .custom-radio { width: 18px; height: 18px; border: 2px solid #cbd5e1; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: .2s; }
        .custom-radio.on { border-color: #4f46e5; }
        .radio-dot { width: 8px; height: 8px; border-radius: 50%; background: #4f46e5; opacity: 0; transform: scale(0); transition: .2s; }
        .custom-radio.on .radio-dot { opacity: 1; transform: scale(1); }
        .ib-payer { flex: 1; border: 1px solid #e2e8f0; background: #fff; border-radius: 10px; padding: 7px 4px; font-size: .78rem; font-weight: 700; color: #64748b; transition: .15s; }
        .ib-payer.on { border-color: #4f46e5; color: #4f46e5; background: #eef2ff; }
        .ib-order-toggle { display: flex; align-items: center; gap: 12px; cursor: pointer; user-select: none; }
        .ib-cur { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: .7rem; color: #94a3b8; font-weight: 700; }
        .ib-item { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px dashed #eef0f3; }
        .ib-item:last-of-type { border-bottom: none; }
        .ib-np-list { position: absolute; top: calc(100% + 3px); left: 0; right: 0; background: #fff; border: 1px solid #e6e8ee; border-radius: 10px; box-shadow: 0 12px 30px rgba(16,24,40,.14); z-index: 70; max-height: 220px; overflow-y: auto; padding: 4px; }
        .ib-np-list button { display: block; width: 100%; text-align: left; border: none; background: transparent; padding: 7px 9px; border-radius: 7px; font-size: .8rem; }
        .ib-np-list button:hover { background: #f0f2f5; }
        .ib-pay-row { display: flex; gap: 6px; }
        .ib-pay { flex: 1; border: 1px solid #e3e6ea; background: #fff; border-radius: 10px; padding: 8px 4px; display: flex; flex-direction: column; align-items: center; gap: 3px; font-size: .68rem; font-weight: 600; color: #475467; }
        .ib-pay i { font-size: 16px; }
        .ib-pay.active { border-color: #0084ff; background: #eaf3ff; color: #0a66c2; }
        .ib-order { display: flex; align-items: center; gap: 8px; padding: 8px 0 4px; text-decoration: none; color: inherit; font-size: .82rem; }
        .ib-order-wrap { border-bottom: 1px dashed #eef0f3; padding-bottom: 7px; margin-bottom: 4px; }
        .ib-order-wrap:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .ib-copy { border: none; background: transparent; color: #94a3b8; padding: 0 4px; font-size: .78rem; line-height: 1; }
        .ib-copy:hover { color: #0d6efd; }
        /* === AI-агент (поки болванка-шаблон) === */
        .ib-ai-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; box-shadow: 0 4px 12px rgba(139,92,246,.3); }
        .ib-ai-badge { font-size: .62rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #f1f5f9; color: #64748b; }
        .ib-ai-badge.on { background: #dcfce7; color: #15803d; }
        .ib-switch { cursor: pointer; }
        .ib-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; margin: 0; }
        .ib-switch input { opacity: 0; width: 0; height: 0; }
        .ib-slider { position: absolute; inset: 0; background: #e2e8f0; border-radius: 999px; transition: .25s; }
        .ib-slider::before { content: ''; position: absolute; width: 18px; height: 18px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .25s; box-shadow: 0 1px 3px rgba(0,0,0,.25); }
        .ib-switch input:checked + .ib-slider { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
        .ib-switch input:checked + .ib-slider::before { transform: translateX(20px); }
        .ib-cat-tabs { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 6px; }
        .ib-cat-tab { border: 1px solid #e3e6ea; background: #fff; border-radius: 999px; padding: 4px 12px; font-size: .8rem; font-weight: 600; color: #475467; white-space: nowrap; }
        .ib-cat-tab.active { background: #0084ff; border-color: #0084ff; color: #fff; }
        .ib-prod-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .ib-prod { border: 1px solid #eef0f3; border-radius: 12px; overflow: hidden; cursor: pointer; background: #fff; }
        .ib-prod:hover { border-color: #0084ff; }
        .ib-prod img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; background: #f5f6f8; }
        .ib-prod .ph { width: 100%; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; color: #c3c8d2; font-size: 26px; background: #f5f6f8; }
        .ib-prod .tt { font-size: .78rem; font-weight: 600; padding: 6px 8px 2px; line-height: 1.25; max-height: 2.7em; overflow: hidden; }
        .ib-prod .pr { font-size: .78rem; color: #2fb344; font-weight: 700; padding: 0 8px 8px; }
        .ib-pcard { background: #fff; border-radius: 14px; margin-bottom: 10px; box-shadow: 0 1px 2px rgba(16,24,40,.06); overflow: hidden; }
        .ib-prow { display: flex; align-items: center; gap: 12px; padding: 12px 14px; cursor: pointer; }
        .ib-prow img { width: 48px; height: 48px; border-radius: 10px; object-fit: cover; background: #f1f3f6; flex-shrink: 0; }
        .ib-prow .ph { width: 48px; height: 48px; border-radius: 10px; background: #f1f3f6; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #c3c8d2; font-size: 20px; }
        .ib-ptitle { font-weight: 700; font-size: .92rem; line-height: 1.3; }
        .ib-sku { font-size: .7rem; background: #f1f3f6; border: 1px solid #e6e8ee; border-radius: 6px; padding: 1px 6px; color: #475467; white-space: nowrap; }
        .ib-stock { font-size: .74rem; color: #2fb344; font-weight: 600; white-space: nowrap; }
        .ib-price { color: #0d6efd; font-weight: 800; font-size: 1rem; white-space: nowrap; }
        .ib-price .cur { font-size: .68rem; color: #8a8d91; font-weight: 600; }
        .ib-sizes-btn { border: none; background: #eef2f7; border-radius: 999px; padding: 4px 13px; font-size: .78rem; font-weight: 700; color: #0d6efd; margin-top: 5px; white-space: nowrap; }
        .ib-vrow { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0 10px 10px; border: 1px solid #f0f2f5; border-radius: 12px; padding: 10px 12px; background: #fff; }
        .ib-add-btn { border: none; border-radius: 999px; padding: 5px 14px; font-size: .8rem; font-weight: 700; background: #eef2f7; color: #0d6efd; white-space: nowrap; margin-top: 4px; }
        .ib-add-btn.added { background: #0d6efd; color: #fff; }
        .ib-info .btn { font-size: .82rem; }

        .ib-empty { margin: auto; text-align: center; color: #94a3b8; padding: 24px; }
        .ib-empty i { font-size: 2.4rem; opacity: .35; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #d8dae2; border-radius: 8px; }
    </style>

    <div class="ib-wrap d-flex">

        {{-- 25% --}}
        <div class="ib-col ib-list">
            <div class="ib-head d-flex align-items-center justify-content-between">
                <span class="ib-title"><i class="bi bi-chat-dots-fill text-primary me-1"></i>Чат</span>
                <div class="d-flex gap-2">
                    <button onclick="syncHistory(this)" class="ib-iconbtn" title="Імпортувати історію"><i class="bi bi-cloud-arrow-down"></i></button>
                    <button onclick="loadConversations()" class="ib-iconbtn" title="Оновити"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
            </div>
            <div class="ib-tabs">
                <button id="tab-msgs" class="ib-tab active" onclick="setTab('msgs')"><i class="bi bi-chat-left-text me-1"></i>Повідомлення</button>
                <button id="tab-comments" class="ib-tab" onclick="setTab('comments')"><i class="bi bi-chat-square-quote me-1"></i>Коментарі <span id="cm-badge" class="ib-tab-badge d-none">0</span></button>
            </div>
            <div id="pane-msgs">
                <div class="ib-search">
                    <i class="bi bi-search"></i>
                    <input id="search" placeholder="Пошук за іменем…" autocomplete="off" oninput="onSearch(this.value)">
                </div>
                <div class="ib-filters">
                    <button class="ib-chip active" data-f="all" onclick="setFilter('all', this)">Усі</button>
                    <button class="ib-chip" data-f="facebook" onclick="setFilter('facebook', this)"><i class="bi bi-messenger"></i> Messenger</button>
                    <button class="ib-chip" data-f="instagram" onclick="setFilter('instagram', this)"><i class="bi bi-instagram"></i> Instagram</button>
                </div>
            </div>
            <div id="pane-comments" class="d-none">
                <div class="ib-filters">
                    <button class="ib-chip active" data-cf="all" onclick="setCmFilter('all', this)">Усі</button>
                    <button class="ib-chip" data-cf="facebook" onclick="setCmFilter('facebook', this)"><i class="bi bi-messenger"></i> Facebook</button>
                    <button class="ib-chip" data-cf="instagram" onclick="setCmFilter('instagram', this)"><i class="bi bi-instagram"></i> Instagram</button>
                    <button class="ib-chip" id="cm-only-new" onclick="cmOnlyNew=!cmOnlyNew;this.classList.toggle('active',cmOnlyNew);renderComments()">Без відповіді</button>
                </div>
            </div>
            <div id="conv-list" class="ib-convs"><div class="ib-empty">Завантаження…</div></div>
            <div id="comments-list" class="ib-convs d-none"><div class="ib-empty">Завантаження…</div></div>
        </div>

        {{-- 50% --}}
        <div class="ib-col ib-thread">
            <div id="thread-empty" class="ib-empty m-auto"><i class="bi bi-chat-left-text d-block mb-2"></i>Обери діалог зліва</div>
            <div id="thread" class="d-none ib-col h-100">
                <div id="thread-header" class="ib-thead"></div>
                <div id="thread-messages" class="ib-msgs"></div>
                <div class="ib-composer">
                    <div id="emoji-pop" class="ib-pop d-none"><div class="ib-emoji-grid"></div></div>
                    <div id="tpl-pop" class="ib-pop d-none"><div class="ib-tpl"><div class="text-muted small p-2">Завантаження…</div></div></div>
                    <div id="attach-preview" class="ib-attach-preview d-none"></div>
                    <form id="reply-form" class="ib-box">
                        <span class="ib-box-av" id="composer-av"><i class="bi bi-shop"></i></span>
                        <textarea id="reply-input" rows="1" placeholder="Відповідь у Messenger…"></textarea>
                        <div class="ib-box-tools">
                            <button type="button" class="ib-tool" title="Галерея зображень" onclick="openGalleryModal()"><i class="bi bi-bag"></i></button>
                            <button type="button" class="ib-tool" title="Фото / файл" onclick="document.getElementById('file-input').click()"><i class="bi bi-paperclip"></i></button>
                            <button type="button" class="ib-tool" title="Швидкі відповіді" onclick="toggleTpl()"><i class="bi bi-chat"></i></button>
                            <button type="button" class="ib-tool" title="Емодзі" onclick="toggleEmoji()"><i class="bi bi-emoji-smile"></i></button>
                            <button type="button" class="ib-tool" id="like-btn" title="Надіслати 👍" onclick="sendLike()"><i class="bi bi-hand-thumbs-up-fill"></i></button>
                            <button type="submit" class="ib-tool ib-send-ic d-none" id="send-btn" title="Надіслати"><i class="bi bi-send-fill"></i></button>
                        </div>
                        <input type="file" id="file-input" class="d-none" accept="image/*,application/pdf,.doc,.docx" multiple onchange="stageFile(this)">
                    </form>
                    <div id="reply-error" class="text-danger small mt-2 d-none px-2"></div>
                </div>
            </div>
        </div>

        {{-- 25% --}}
        <div class="ib-col ib-info">
            <div id="info-empty" class="ib-empty m-auto"><i class="bi bi-person-circle d-block mb-2"></i>Інформація про контакт</div>
            <div id="info" class="d-none" style="overflow-y:auto"></div>
        </div>
    </div>

    @push('scripts')
    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        let activeId = null, allConvs = [], filter = 'all', search = '', tplItems = [], tplLoaded = false;

        const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
        const chLabel = (ch) => ch === 'instagram' ? 'Instagram' : 'Messenger';
        const chIcon = (ch) => ch === 'instagram'
            ? '<i class="bi bi-instagram" style="color:#E1306C"></i>'
            : '<i class="bi bi-messenger" style="color:#0084FF"></i>';

        function avatar(name, url, ch, size = 42) {
            const letter = esc((name || '?').trim().charAt(0).toUpperCase() || '?');
            const inner = url ? `<img src="${esc(url)}" onerror="this.remove()">` : letter;
            return `<span class="ib-av"><span class="circle" style="width:${size}px;height:${size}px;font-size:${size/2.4}px">${inner}</span><span class="ch">${chIcon(ch)}</span></span>`;
        }

        const CONV_PAGE = 25;
        let convsHasMore = false, convsLoadingMore = false;

        async function loadConversations() {
            try {
                // Оновлюємо стільки, скільки вже показано (мінімум одна пачка).
                const limit = Math.max(allConvs.length, CONV_PAGE);
                const res = await fetch('{{ route('inbox.conversations') }}?offset=0&limit=' + limit, { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                allConvs = json.data || [];
                convsHasMore = !!json.has_more;
                renderConvList();
            } catch (e) {}
        }

        async function loadMoreConvs() {
            if (!convsHasMore || convsLoadingMore) return;
            convsLoadingMore = true;
            renderConvList(); // показати спінер унизу
            try {
                const res = await fetch('{{ route('inbox.conversations') }}?offset=' + allConvs.length + '&limit=' + CONV_PAGE, { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                allConvs = allConvs.concat(json.data || []);
                convsHasMore = !!json.has_more;
            } catch (e) {}
            convsLoadingMore = false;
            renderConvList();
        }

        function renderConvList() {
            const el = document.getElementById('conv-list');
            let items = allConvs;
            if (filter !== 'all') items = items.filter(c => c.channel === filter);
            if (search) items = items.filter(c => (c.contact_name || '').toLowerCase().includes(search));
            if (!items.length) { el.innerHTML = '<div class="ib-empty">Нічого не знайдено</div>'; return; }
            el.innerHTML = items.map(c => {
                const st = chatStatuses.find(s => s.id === c.chat_status_id);
                const badge = st && !st.is_default
                    ? `<span class="ib-st-badge" style="background:${esc(st.color)}1f;color:${esc(st.color)}">${esc(st.name)}</span>`
                    : '';
                return `
                <div class="ib-conv ${c.id === activeId ? 'active' : ''} ${c.unread > 0 ? 'unread' : ''}" onclick="openConversation(${c.id})">
                    ${avatar(c.contact_name, c.avatar, c.channel, 48)}
                    <div class="meta">
                        <div class="d-flex align-items-center gap-1">
                            <span class="nm text-truncate">${esc(c.contact_name)}</span>
                            ${badge}
                            <span class="ib-time ms-auto">${esc(c.last_at_human || '')}</span>
                        </div>
                        <div class="pv text-truncate">${c.last_direction === 'out' ? 'Ви: ' : ''}${esc(c.last_text || '')}</div>
                        <div class="store text-truncate">${esc(c.store)}</div>
                    </div>
                    ${c.unread > 0 ? '<span class="ib-dot"></span>' : ''}
                </div>`;
            }).join('')
                + (convsLoadingMore ? '<div class="ib-conv-more"><span class="spinner-border spinner-border-sm"></span></div>' : '');
        }

        function setFilter(f, btn) {
            filter = f;
            document.querySelectorAll('.ib-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderConvList();
        }
        function onSearch(v) { search = (v || '').toLowerCase().trim(); renderConvList(); }

        let chatStatuses = [];
        async function loadChatStatuses() {
            try {
                const res = await fetch('{{ route('chatStatuses.list') }}', { headers: { 'Accept': 'application/json' } });
                chatStatuses = await res.json();
            } catch (e) {}
        }

        async function setChatStatus(val) {
            if (!activeId) return;
            const id = val ? parseInt(val, 10) : null;
            try {
                await fetch(`/api/inbox/conversations/${activeId}/status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ chat_status_id: id })
                });
                const conv = allConvs.find(x => x.id === activeId);
                if (conv) conv.chat_status_id = id;
                const st = chatStatuses.find(s => s.id === id);
                document.querySelector('.ib-status-wrap')?.style.setProperty('--st', st?.color || '#adb5bd');
                renderConvList();
            } catch (e) {}
        }

        function toggleThreadMenu(e) {
            e.stopPropagation();
            document.getElementById('th-menu')?.classList.toggle('d-none');
        }

        async function refreshConversation(btn) {
            if (!activeId) return;
            const ic = btn.querySelector('i');
            btn.disabled = true; ic.classList.add('ib-spin');
            try {
                await fetch(`/api/inbox/conversations/${activeId}/refresh`, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
                await openConversation(activeId);
                loadConversations();
            } catch (e) {}
            btn.disabled = false; ic.classList.remove('ib-spin');
        }

        async function clearChat() {
            document.getElementById('th-menu')?.classList.add('d-none');
            if (!activeId || !confirm('Очистити чат? Всі повідомлення цієї розмови буде видалено з CRM (у Facebook вони залишаться). Кнопка «оновити» зможе підтягнути їх знову.')) return;
            await fetch(`/api/inbox/conversations/${activeId}/clear`, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
            await openConversation(activeId);
            loadConversations();
        }

        async function deleteChat() {
            document.getElementById('th-menu')?.classList.add('d-none');
            if (!activeId || !confirm('Видалити чат повністю? Розмову, контакт і всі повідомлення буде видалено з бази. Якщо клієнт напише знову — чат зʼявиться як новий.')) return;
            await fetch(`/api/inbox/conversations/${activeId}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
            activeId = null;
            const t = document.getElementById('thread');
            t.classList.add('d-none'); t.classList.remove('d-flex');
            document.getElementById('thread-empty').classList.remove('d-none');
            document.getElementById('info')?.classList.add('d-none');
            document.getElementById('info-empty')?.classList.remove('d-none');
            loadConversations();
        }

        async function openConversation(id) {
            if (id !== activeId && !sendingNow) { staged = []; renderStaged(); }
            activeId = id;
            if (!chatStatuses.length) await loadChatStatuses();
            const res = await fetch(`/api/inbox/conversations/${id}/messages`, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            const c = data.conversation;
            document.getElementById('thread-empty').classList.add('d-none');
            const t = document.getElementById('thread');
            t.classList.remove('d-none'); t.classList.add('d-flex');
            const curSt = chatStatuses.find(s => s.id === c.chat_status_id);
            const stOpts = '<option value="">— без статусу —</option>'
                + chatStatuses.map(s => `<option value="${s.id}" ${c.chat_status_id === s.id ? 'selected' : ''}>${esc(s.name)}</option>`).join('');
            document.getElementById('thread-header').innerHTML = `
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2" style="min-width:0">
                        ${avatar(c.contact_name, c.avatar, c.channel, 44)}
                        <div class="ms-1" style="min-width:0">
                            <div class="ib-th-name">${esc(c.contact_name)}</div>
                            <div class="ib-th-store">${c.conn_id ? `<img src="/inbox/page-avatar/${c.conn_id}" onerror="this.remove()">` : '<i class="bi bi-shop"></i>'}<span>${esc(c.store)}</span></div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <div class="ib-status-wrap" style="--st:${curSt?.color || '#adb5bd'}">
                            <span class="ib-status-dot"></span>
                            <select class="ib-status-sel" onchange="setChatStatus(this.value)">${stOpts}</select>
                        </div>
                        <button type="button" class="ib-thbtn" onclick="refreshConversation(this)" title="Підтягнути історію з Facebook"><i class="bi bi-arrow-clockwise"></i></button>
                        <div class="position-relative">
                            <button type="button" class="ib-thbtn" onclick="toggleThreadMenu(event)" title="Дії"><i class="bi bi-three-dots"></i></button>
                            <div id="th-menu" class="ib-th-pop d-none">
                                <button type="button" onclick="clearChat()"><i class="bi bi-eraser me-2"></i>Очистити чат</button>
                                <button type="button" class="danger" onclick="deleteChat()"><i class="bi bi-trash me-2"></i>Видалити чат</button>
                            </div>
                        </div>
                    </div>
                </div>`;
            document.getElementById('reply-input').placeholder = 'Відповідь у ' + chLabel(c.channel) + '…';
            const av = document.getElementById('composer-av');
            av.innerHTML = '<i class="bi bi-shop"></i>';
            if (c.conn_id) {
                const img = new Image();
                img.onload = () => { av.innerHTML = ''; av.appendChild(img); };
                img.src = '/inbox/page-avatar/' + c.conn_id;
            }
            renderMessages(data.messages, { forceBottom: true });
            if (panelConvId !== id) { resetOrderPanel(c); panelConvId = id; }
            renderInfo(c);
            loadPanel();
            renderConvList();
        }

        // ====== ПРАВА ПАНЕЛЬ: ОФОРМЛЕННЯ ЗАМОВЛЕННЯ З ЧАТУ ======
        let currentConv = null, panelConvId = null, panel = null, orderFormOpen = false;
        let custDraft = { first_name: '', last_name: '', phone: '' };
        let custEdit = false;
        let orderItems = [];
        let delivery = { city_ref: '', settlement_ref: '', city_name: '', warehouse_ref: '', warehouse_name: '', payer: 'recipient' };
        let payment = { method: 'cod', prepay_amount: '' };

        function toggleOrderForm() {
            orderFormOpen = !orderFormOpen;
            renderInfo(currentConv);
        }

        function resetOrderPanel(c) {
            panel = null; custEdit = false; orderFormOpen = false;
            const parts = (c?.contact_name || '').trim().split(/\s+/);
            custDraft = { first_name: parts[0] || '', last_name: parts.slice(1).join(' ') || '', phone: '' };
            orderItems = [];
            delivery = { city_ref: '', settlement_ref: '', city_name: '', warehouse_ref: '', warehouse_name: '', payer: 'recipient' };
            payment = { method: 'cod', prepay_amount: '' };
        }

        async function loadPanel() {
            if (!activeId) return;
            const forId = activeId;
            try {
                const res = await fetch(`/api/inbox/conversations/${forId}/panel`, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (forId !== activeId) return; // встигли перемкнутись
                panel = data;
            } catch (e) { panel = { customer: null, orders: [] }; }
            if (currentConv) renderInfo(currentConv);
        }

        function editCustomer() {
            const cust = panel?.customer;
            if (cust) custDraft = { first_name: cust.first_name || '', last_name: cust.last_name || '', phone: cust.phone || '' };
            custEdit = true;
            renderInfo(currentConv);
        }

        // Імʼя/прізвище — лише кирилиця (укр/рос); телефон — 0XXXXXXXXX або +380XXXXXXXXX.
        const cyrOk = (s) => !s || !s.trim() || /^[Ѐ-ӿ'’ʼ\-\s]+$/.test(s.trim());
        const normPhone = (s) => (s || '').replace(/[\s\-().]/g, '');
        const phoneOk = (s) => /^(\+?38)?0\d{9}$/.test(normPhone(s));

        function vField(el, kind) {
            const v = el.value;
            let ok = kind === 'phone' ? (!v.trim() || phoneOk(v)) : cyrOk(v);
            el.classList.toggle('bad', !ok);
            document.getElementById('err-' + kind)?.classList.toggle('d-none', ok);
            return ok;
        }

        async function saveCustomer(btn) {
            const errBox = document.getElementById('cust-err');
            errBox?.classList.add('d-none');
            let bad = false;
            if (!custDraft.first_name.trim() || !cyrOk(custDraft.first_name)) { document.getElementById('cf-first')?.classList.add('bad'); document.getElementById('err-first')?.classList.remove('d-none'); bad = true; }
            if (!cyrOk(custDraft.last_name)) { document.getElementById('cf-last')?.classList.add('bad'); document.getElementById('err-last')?.classList.remove('d-none'); bad = true; }
            if (!phoneOk(custDraft.phone)) { document.getElementById('cf-phone')?.classList.add('bad'); document.getElementById('err-phone')?.classList.remove('d-none'); bad = true; }
            if (bad) return;

            btn.disabled = true;
            try {
                const res = await fetch(`/api/inbox/conversations/${activeId}/attach-customer`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({
                        first_name: custDraft.first_name.trim(),
                        last_name: custDraft.last_name.trim(),
                        phone: normPhone(custDraft.phone),
                    })
                });
                if (!res.ok) {
                    const d = await res.json().catch(() => ({}));
                    throw new Error(d.message || 'Не вдалося зберегти клієнта');
                }
                custEdit = false;
                await loadPanel();
            } catch (e) {
                if (errBox) { errBox.textContent = e.message; errBox.classList.remove('d-none'); }
            }
            btn.disabled = false;
        }

        // --- Нова пошта: пошук міста і відділення ---
        let npCityT = null, npWhT = null, npCityItems = [], npWhItems = [];
        function npCitySearch(q) {
            delivery.city_name = q; delivery.city_ref = ''; delivery.warehouse_ref = ''; delivery.warehouse_name = '';
            clearTimeout(npCityT);
            const list = document.getElementById('np-city-list');
            if ((q || '').trim().length < 2) { list?.classList.add('d-none'); return; }
            npCityT = setTimeout(async () => {
                try {
                    const res = await fetch(`/nova-poshta/cities?q=${encodeURIComponent(q.trim())}`, { headers: { 'Accept': 'application/json' } });
                    npCityItems = (await res.json()).data || [];
                    const l = document.getElementById('np-city-list');
                    if (!l) return;
                    l.innerHTML = npCityItems.length
                        ? npCityItems.map((ct, i) => `<button type="button" onclick="pickCity(${i})">${esc(ct.name)}</button>`).join('')
                        : '<div class="text-muted small p-2">Не знайдено</div>';
                    l.classList.remove('d-none');
                } catch (e) {}
            }, 300);
        }
        function pickCity(i) {
            const ct = npCityItems[i]; if (!ct) return;
            delivery.city_ref = ct.ref; delivery.settlement_ref = ct.settlement_ref || ''; delivery.city_name = ct.name;
            delivery.warehouse_ref = ''; delivery.warehouse_name = '';
            renderInfo(currentConv);
            setTimeout(() => document.getElementById('np-wh')?.focus(), 60);
        }
        function npWhSearch(q) {
            delivery.warehouse_name = q; delivery.warehouse_ref = '';
            clearTimeout(npWhT);
            if (!delivery.city_ref) return;
            npWhT = setTimeout(async () => {
                try {
                    const res = await fetch(`/nova-poshta/warehouses?city_ref=${encodeURIComponent(delivery.city_ref)}&q=${encodeURIComponent((q || '').trim())}`, { headers: { 'Accept': 'application/json' } });
                    npWhItems = (await res.json()).data || [];
                    const l = document.getElementById('np-wh-list');
                    if (!l) return;
                    l.innerHTML = npWhItems.length
                        ? npWhItems.map((w, i) => `<button type="button" onclick="pickWh(${i})">${esc(w.name)}</button>`).join('')
                        : '<div class="text-muted small p-2">Не знайдено</div>';
                    l.classList.remove('d-none');
                } catch (e) {}
            }, 300);
        }
        function pickWh(i) {
            const w = npWhItems[i]; if (!w) return;
            delivery.warehouse_ref = w.ref; delivery.warehouse_name = w.name;
            renderInfo(currentConv);
        }

        function removeOrderItem(i) { orderItems.splice(i, 1); renderInfo(currentConv); }
        function copyTtn(btn, ttn) {
            navigator.clipboard?.writeText(ttn);
            btn.innerHTML = '<i class="bi bi-check2 text-success"></i>';
            setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1200);
        }

        async function toggleAi(input) {
            if (!activeId) return;
            const enabled = input.checked;
            try {
                const res = await fetch(`/api/inbox/conversations/${activeId}/ai`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ enabled })
                });
                if (!res.ok) throw new Error();
                if (currentConv) currentConv.ai_enabled = enabled;
                renderInfo(currentConv);
            } catch (e) { input.checked = !enabled; }
        }
        function setPayMethod(m) { payment.method = m; renderInfo(currentConv); }

        async function saveOrderFromChat(btn) {
            const err = document.getElementById('order-error');
            err.classList.add('d-none');
            const cust = panel?.customer;
            const useCard = cust && !custEdit;
            const first = useCard ? (cust.first_name || '') : custDraft.first_name;
            const last = useCard ? (cust.last_name || '') : custDraft.last_name;
            const phone = ((useCard ? cust.phone : custDraft.phone) || '').trim();
            if (!phone) { err.textContent = 'Вкажіть телефон у блоці «Клієнт» і збережіть'; err.classList.remove('d-none'); return; }
            if (!orderItems.length) { err.textContent = 'Додайте хоча б один товар'; err.classList.remove('d-none'); return; }

            btn.disabled = true; const prev = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Зберігаю…';
            const payload = {
                customer: { first_name: first, last_name: last, phone },
                order: {
                    status: 'new', payment_status: 'unpaid', currency: 'UAH',
                    source: currentConv.channel === 'instagram' ? 'instagram' : 'facebook',
                    comment_internal: 'Створено з чату',
                },
                items: orderItems.map(it => ({
                    product_id: it.product_id, product_variant_id: it.product_variant_id,
                    title: it.title, sku: it.sku, size: it.size,
                    qty: it.qty, price: parseFloat(it.price) || 0,
                })),
                payment: { method: payment.method, prepay_amount: payment.method === 'prepay' ? (parseFloat(payment.prepay_amount) || 0) : 0, currency: 'UAH' },
                delivery: {
                    carrier: 'nova_poshta', delivery_type: 'warehouse', payer: delivery.payer,
                    city_ref: delivery.city_ref || null, settlement_ref: delivery.settlement_ref || null, city_name: delivery.city_name || null,
                    warehouse_ref: delivery.warehouse_ref || null, warehouse_name: delivery.warehouse_name || null,
                    recipient_name: (first + ' ' + last).trim() || null, recipient_phone: phone,
                },
            };
            try {
                const res = await fetch('{{ route('orders.store') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Не вдалося зберегти замовлення');
                const orderId = data.data?.id;
                if (orderId) {
                    await fetch(`/api/inbox/conversations/${activeId}/attach-order`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                        body: JSON.stringify({ order_id: orderId })
                    });
                }
                orderItems = [];
                delivery = { city_ref: '', settlement_ref: '', city_name: '', warehouse_ref: '', warehouse_name: '', payer: 'recipient' };
                payment = { method: 'cod', prepay_amount: '' };
                orderFormOpen = false;
                await openConversation(activeId); // оновлює статус у шапці та списку
                await loadPanel();
            } catch (e) {
                err.textContent = e.message || 'Помилка збереження';
                err.classList.remove('d-none');
            }
            btn.disabled = false; btn.innerHTML = prev;
        }

        function renderInfo(c) {
            currentConv = c;
            document.getElementById('info-empty').classList.add('d-none');
            const box = document.getElementById('info');
            box.classList.remove('d-none');

            const cust = panel?.customer;

            // --- Картка «Клієнт» (як CustomerBlock в адмінці) ---
            let custInner;
            if (cust && !custEdit) {
                const name = ((cust.first_name || '') + ' ' + (cust.last_name || '')).trim() || c.contact_name;
                custInner = `
                    <div class="d-flex align-items-center gap-3">
                        ${avatar(c.contact_name, c.avatar, c.channel, 52)}
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <div class="fw-bold text-dark text-truncate" style="font-size:.92rem">${esc(name)}</div>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2" style="font-size:.64rem">Клієнт</span>
                            </div>
                            <div class="text-secondary" style="font-size:.8rem"><i class="bi bi-telephone-fill me-1 text-muted"></i>${esc(cust.phone || '—')}</div>
                        </div>
                        <button class="btn-action edit" onclick="editCustomer()" title="Редагувати"><i class="bi bi-pencil-square"></i></button>
                    </div>`;
            } else {
                custInner = `
                    <div class="d-flex align-items-center gap-2 mb-3">
                        ${avatar(c.contact_name, c.avatar, c.channel, 40)}
                        <div class="fw-bold text-truncate" style="font-size:.88rem">${esc(c.contact_name)}</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-custom">Мобільний телефон</label>
                        <div class="input-group-custom">
                            <i class="bi bi-telephone input-icon-left"></i>
                            <input id="cf-phone" class="form-control form-control-sm custom-input has-icon" placeholder="0XX XXX XX XX" value="${esc(custDraft.phone)}" oninput="custDraft.phone=this.value;vField(this,'phone')">
                        </div>
                        <div id="err-phone" class="ib-ferr d-none">Формат: 0XXXXXXXXX або +380…</div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label-custom">Імʼя</label>
                            <input id="cf-first" class="form-control form-control-sm custom-input ${cyrOk(custDraft.first_name) ? '' : 'bad'}" placeholder="Кирилицею" value="${esc(custDraft.first_name)}" oninput="custDraft.first_name=this.value;vField(this,'first')">
                            <div id="err-first" class="ib-ferr ${cyrOk(custDraft.first_name) ? 'd-none' : ''}">Лише кирилиця</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label-custom">Прізвище</label>
                            <input id="cf-last" class="form-control form-control-sm custom-input ${cyrOk(custDraft.last_name) ? '' : 'bad'}" placeholder="Кирилицею" value="${esc(custDraft.last_name)}" oninput="custDraft.last_name=this.value;vField(this,'last')">
                            <div id="err-last" class="ib-ferr ${cyrOk(custDraft.last_name) ? 'd-none' : ''}">Лише кирилиця</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary-gradient w-100 fw-semibold py-2" style="font-size:.86rem" onclick="saveCustomer(this)"><i class="bi bi-check-lg me-1"></i>Зберегти клієнта</button>
                        ${cust ? '<button class="btn-action delete" onclick="custEdit=false;renderInfo(currentConv)" title="Скасувати"><i class="bi bi-x-lg"></i></button>' : ''}
                    </div>
                    <div id="cust-err" class="ib-ferr mt-2 d-none"></div>`;
            }

            // --- Товари ---
            const itemsHtml = orderItems.length ? orderItems.map((it, i) => `
                <div class="d-flex align-items-center gap-2 py-2" style="${i ? 'border-top:1px solid #f1f5f9' : ''}">
                    ${it.photo
                        ? `<img src="${esc(it.photo)}" style="width:38px;height:38px;border-radius:9px;object-fit:cover;flex-shrink:0">`
                        : '<div style="width:38px;height:38px;border-radius:9px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#c3c8d2;flex-shrink:0"><i class="bi bi-image"></i></div>'}
                    <div class="flex-grow-1" style="min-width:0">
                        <div class="fw-semibold text-truncate" style="font-size:.82rem">${esc(it.title)}</div>
                        <div class="text-muted d-flex align-items-center gap-2 mt-1" style="font-size:.72rem">
                            ${it.size ? `<span class="ib-sku">${esc(it.size)}</span>` : ''}
                            <span>${it.qty} шт × ${it.price} грн</span>
                        </div>
                    </div>
                    <div class="fw-bold" style="font-size:.82rem;white-space:nowrap">${(it.qty * (parseFloat(it.price) || 0)).toFixed(0)} грн</div>
                    <button class="btn-action delete" onclick="removeOrderItem(${i})" title="Прибрати"><i class="bi bi-trash3"></i></button>
                </div>`).join('') : '<div class="text-muted py-2" style="font-size:.8rem">Поки порожньо — натисніть «Обрати»</div>';
            const totalSum = orderItems.reduce((s, it) => s + it.qty * (parseFloat(it.price) || 0), 0);

            // --- Доставка ---
            const deliveryHtml = `
                <div class="mb-2">
                    <label class="form-label-custom">Місто</label>
                    <div class="input-group-custom">
                        <i class="bi bi-geo-alt input-icon-left"></i>
                        <input id="np-city" class="form-control form-control-sm custom-input has-icon" placeholder="Напр., Рівне" value="${esc(delivery.city_name)}" oninput="npCitySearch(this.value)" autocomplete="off">
                        <div id="np-city-list" class="ib-np-list d-none"></div>
                    </div>
                </div>
                <div class="mb-2 ${delivery.city_ref ? '' : 'd-none'}">
                    <label class="form-label-custom">Відділення / поштомат</label>
                    <div class="input-group-custom">
                        <i class="bi bi-box2 input-icon-left"></i>
                        <input id="np-wh" class="form-control form-control-sm custom-input has-icon" placeholder="Пошук відділення…" value="${esc(delivery.warehouse_name)}" oninput="npWhSearch(this.value)" onfocus="npWhSearch(this.value)" autocomplete="off">
                        <div id="np-wh-list" class="ib-np-list d-none"></div>
                    </div>
                </div>
                <label class="form-label-custom mt-1">Платник доставки</label>
                <div class="d-flex gap-2">
                    <button type="button" class="ib-payer ${delivery.payer === 'recipient' ? 'on' : ''}" onclick="delivery.payer='recipient';renderInfo(currentConv)">Отримувач</button>
                    <button type="button" class="ib-payer ${delivery.payer === 'sender' ? 'on' : ''}" onclick="delivery.payer='sender';renderInfo(currentConv)">Відправник</button>
                </div>`;

            // --- Оплата (як PaymentBlock в адмінці) ---
            const PAY = [
                ['cod', 'Накладений платіж', 'Оплата у відділенні пошти', 'bi-box-seam'],
                ['card', 'Оплата на рахунок', 'Повна оплата за реквізитами', 'bi-credit-card'],
                ['prepay', 'Передоплата', 'Часткова оплата наперед', 'bi-wallet2'],
            ];
            const payHtml = '<div class="d-flex flex-column gap-2">' + PAY.map(([v, l, d, ic]) => `
                <div class="payment-card ${payment.method === v ? 'selected' : ''}" onclick="setPayMethod('${v}')">
                    <div class="d-flex align-items-center gap-3">
                        <div class="icon-box ${payment.method === v ? 'icon-box-active' : ''}"><i class="bi ${ic}"></i></div>
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="fw-bold text-dark" style="font-size:.84rem">${l}</span>
                                <span class="custom-radio ${payment.method === v ? 'on' : ''}"><span class="radio-dot"></span></span>
                            </div>
                            <div class="text-muted" style="font-size:.7rem">${d}</div>
                        </div>
                    </div>
                </div>`).join('') + '</div>'
                + (payment.method === 'prepay' ? `
                <div class="p-3 mt-2 rounded-3" style="background:#f8fafc;border:1px dashed #e2e8f0">
                    <label class="form-label-custom">Внесена сума</label>
                    <div class="input-group-custom">
                        <input class="form-control form-control-sm custom-input" type="number" min="0" placeholder="0" value="${esc(payment.prepay_amount)}" oninput="payment.prepay_amount=this.value">
                        <span class="ib-cur">UAH</span>
                    </div>
                </div>` : '');

            // --- Форма замовлення (розгортається) ---
            const formHtml = !orderFormOpen ? '' : `
                <div class="mt-3 pt-3" style="border-top:1px solid #f1f5f9">
                    <div class="ib-sec-title mb-2"><i class="bi bi-box-seam text-warning me-2"></i>Товари
                        <button class="btn btn-white border shadow-sm ms-auto" style="font-size:.74rem;padding:3px 11px;border-radius:8px" onclick="openProductModal()"><i class="bi bi-plus-lg me-1"></i>Обрати</button>
                    </div>
                    ${itemsHtml}
                    ${orderItems.length ? `<div class="d-flex justify-content-between pt-2 mt-1" style="border-top:1px solid #f1f5f9"><span class="text-muted" style="font-size:.8rem">Разом</span><span class="fw-bold" style="font-size:.9rem">${totalSum.toFixed(0)} грн</span></div>` : ''}
                </div>
                <div class="mt-3 pt-3" style="border-top:1px solid #f1f5f9">
                    <div class="ib-sec-title mb-2"><i class="bi bi-truck text-dark me-2"></i>Доставка — Нова пошта</div>
                    ${deliveryHtml}
                </div>
                <div class="mt-3 pt-3" style="border-top:1px solid #f1f5f9">
                    <div class="ib-sec-title mb-2"><i class="bi bi-credit-card text-success me-2"></i>Оплата</div>
                    ${payHtml}
                </div>
                <button class="btn btn-primary-gradient w-100 fw-semibold py-2 mt-3" onclick="saveOrderFromChat(this)"><i class="bi bi-check-lg me-1"></i>Зберегти замовлення</button>
                <div id="order-error" class="ib-ferr mt-2 d-none"></div>`;

            // --- Замовлення клієнта ---
            const ordersCard = (panel?.orders || []).length ? `
                <div class="clean-card-sm">
                    <div class="ib-sec-title mb-2"><i class="bi bi-receipt text-primary me-2"></i>Замовлення клієнта</div>
                    ${panel.orders.map(o => `
                        <div class="ib-order-wrap">
                            <a class="ib-order" href="/orders/${o.id}" target="_blank">
                                <span class="fw-bold">#${esc(o.number)}</span>
                                <span class="text-muted" style="font-size:.72rem">${esc(o.date || '')}</span>
                                <span class="ms-auto fw-semibold" style="font-size:.78rem">${o.total ? o.total.toFixed(0) + ' грн' : ''}</span>
                                <span class="ib-st-badge" style="background:${esc(o.status_color || '#888')}1f;color:${esc(o.status_color || '#555')}">${esc(o.status)}</span>
                            </a>
                            ${o.ttn ? `
                            <div class="d-flex align-items-center gap-1 pb-1" style="font-size:.74rem;color:#475467">
                                <i class="bi bi-upc-scan"></i><span>ТТН</span>
                                <span class="fw-semibold">${esc(o.ttn)}</span>
                                <button type="button" class="ib-copy" onclick="copyTtn(this,'${esc(o.ttn)}')" title="Копіювати"><i class="bi bi-clipboard"></i></button>
                            </div>` : ''}
                            ${(o.items || []).map(it => `
                                <div class="d-flex align-items-center gap-2 py-1">
                                    ${it.photo
                                        ? `<img src="${esc(it.photo)}" style="width:26px;height:26px;border-radius:7px;object-fit:cover;flex-shrink:0">`
                                        : '<div style="width:26px;height:26px;border-radius:7px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#c3c8d2;flex-shrink:0;font-size:11px"><i class="bi bi-image"></i></div>'}
                                    <span class="text-truncate" style="font-size:.74rem;color:#475467">${esc(it.title || '')}</span>
                                    ${it.size ? `<span class="ib-sku" style="font-size:.62rem">${esc(it.size)}</span>` : ''}
                                    <span class="text-muted ms-auto" style="font-size:.72rem;white-space:nowrap">× ${it.qty}</span>
                                </div>`).join('')}
                        </div>`).join('')}
                </div>` : '';

            box.innerHTML = `
                <div class="ib-panel">
                    <div class="clean-card-sm">
                        <div class="ib-sec-title mb-3"><i class="bi bi-person text-purple me-2"></i>Клієнт
                            ${cust && !custEdit ? '' : '<span class="ib-cb-badge no ms-auto">не збережено</span>'}
                        </div>
                        ${custInner}
                    </div>
                    <div class="clean-card-sm">
                        <div class="ib-order-toggle" onclick="toggleOrderForm()">
                            <div class="icon-box ${orderFormOpen ? 'icon-box-active' : ''}"><i class="bi bi-bag-plus"></i></div>
                            <span class="fw-bold flex-grow-1 text-dark" style="font-size:.9rem">Додати замовлення</span>
                            <i class="bi bi-chevron-${orderFormOpen ? 'up' : 'down'} text-muted"></i>
                        </div>
                        ${formHtml}
                    </div>
                    ${ordersCard}
                    <div class="clean-card-sm">
                        <div class="d-flex align-items-center gap-3">
                            <div class="ib-ai-icon"><i class="bi bi-stars"></i></div>
                            <div class="flex-grow-1" style="min-width:0">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold text-dark" style="font-size:.9rem">AI-агент</span>
                                    <span class="ib-ai-badge ${c.ai_enabled ? 'on' : ''}">${c.ai_enabled ? 'Увімкнено' : 'Вимкнено'}</span>
                                </div>
                                <div class="text-muted" style="font-size:.72rem">Автовідповіді у цьому чаті</div>
                            </div>
                            <label class="ib-switch">
                                <input type="checkbox" ${c.ai_enabled ? 'checked' : ''} onchange="toggleAi(this)">
                                <span class="ib-slider"></span>
                            </label>
                        </div>
                        ${c.ai_paused_until_human ? `<div class="small mt-2" style="color:#b45309"><i class="bi bi-pause-circle me-1"></i>Бот зачекає до ${esc(c.ai_paused_until_human)} — ти відповів вручну, далі він продовжить сам.</div>` : ''}

                        <div class="mt-3">
                            <div class="d-flex align-items-center mb-1">
                                <span class="fw-semibold" style="font-size:.74rem;color:#6d28d9"><i class="bi bi-stars me-1"></i>Про що тут</span>
                                <button class="btn btn-link p-0 ms-auto text-decoration-none" style="font-size:.7rem;color:#7c3aed" onclick="refreshAiSummary(this)"><i class="bi bi-arrow-clockwise me-1"></i>${c.ai_summary ? 'оновити' : 'скласти'}</button>
                            </div>
                            ${c.ai_summary
                                ? `<div style="background:#f5f3ff;border-radius:9px;padding:8px 10px;font-size:.76rem;line-height:1.5;color:#4c1d95">${esc(c.ai_summary)}</div>`
                                : `<div class="text-muted" style="font-size:.72rem">Підсумку ще нема — натисни «скласти».</div>`}
                        </div>

                        ${c.ai_order ? `
                        <div class="mt-3" style="border:1px solid #e9e5fb;border-radius:11px;padding:10px 12px">
                            <div class="d-flex align-items-center mb-2">
                                <span class="fw-semibold text-dark" style="font-size:.76rem"><i class="bi bi-robot me-1 text-purple"></i>Замовлення від ШІ</span>
                                ${c.ai_order.handled
                                    ? `<span class="ms-auto" style="font-size:.66rem;color:#15803d"><i class="bi bi-check-circle-fill me-1"></i>оформлено${c.ai_order.handled_human ? ' · ' + esc(c.ai_order.handled_human) : ''}</span>`
                                    : `<span class="ms-auto ib-ai-badge on" style="font-size:.6rem">нове</span>`}
                            </div>
                            <div class="d-flex justify-content-between gap-2 py-1" style="font-size:.74rem"><span class="text-muted">Товар</span><span class="text-dark text-end">${esc(c.ai_order.summary || '—')}</span></div>
                            <div class="d-flex justify-content-between gap-2 py-1" style="font-size:.74rem"><span class="text-muted">Оплата</span><span class="text-dark text-end">${esc(c.ai_order.payment || '—')}</span></div>
                            ${c.ai_order.handled ? '' : `<button class="btn btn-sm w-100 mt-2 text-white" style="background:#7c3aed;font-size:.74rem" onclick="markOrderHandled(this)"><i class="bi bi-check-lg me-1"></i>Позначити оформленим</button>`}
                        </div>` : ''}

                        <button class="btn btn-sm btn-outline-secondary w-100 mt-2" style="font-size:.74rem" onclick="resetAiContext(this)" title="Агент забуде стару переписку цієї розмови і почне з чистого аркуша. Повідомлення в чаті лишаються.">
                            <i class="bi bi-eraser me-1"></i>Скинути памʼять ШІ (почати з нуля)
                        </button>
                    </div>
                </div>`;
        }

        async function resetAiContext(btn) {
            if (!activeId || !confirm('ШІ забуде стару переписку цієї розмови і почне з чистого аркуша. Повідомлення в чаті залишаться. Продовжити?')) return;
            btn.disabled = true;
            try {
                const res = await fetch(`/api/inbox/conversations/${activeId}/ai-reset`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error();
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Памʼять скинуто';
                setTimeout(() => { btn.innerHTML = '<i class="bi bi-eraser me-1"></i>Скинути памʼять ШІ (почати з нуля)'; btn.disabled = false; }, 2000);
            } catch (e) {
                btn.disabled = false;
                alert('Не вдалося скинути памʼять');
            }
        }

        async function refreshAiSummary(btn) {
            if (!activeId) return;
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>думаю…';
            try {
                const res = await fetch(`/api/inbox/conversations/${activeId}/ai-summary`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error();
                if (currentConv) {
                    currentConv.ai_summary = data.summary;
                    currentConv.ai_summary_human = data.summary_human;
                    renderInfo(currentConv);
                }
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = orig;
                alert('Не вдалося скласти підсумок (перевір баланс ШІ).');
            }
        }

        async function markOrderHandled(btn) {
            if (!activeId) return;
            btn.disabled = true;
            try {
                const res = await fetch(`/api/inbox/conversations/${activeId}/ai-order-handled`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error();
                if (currentConv && currentConv.ai_order) {
                    currentConv.ai_order.handled = true;
                    currentConv.ai_order.handled_human = data.handled_human;
                    renderInfo(currentConv);
                }
            } catch (e) {
                btn.disabled = false;
                alert('Не вдалося позначити');
            }
        }

        // --- Модалка «Каталог товарів» (як в адмінці) ---
        let prodCats = null, prodItems = [], prodOpen = {}, prodCatId = '', prodQ = '', prodQT = null;

        function ensureProductModal() {
            if (document.getElementById('prod-modal')) return;
            const el = document.createElement('div');
            el.id = 'prod-modal';
            el.className = 'ib-modal d-none';
            el.innerHTML = `
                <div class="ib-modal-card" style="width:min(760px,96vw)">
                    <div class="ib-modal-head">
                        <div>
                            <div class="ib-modal-title">Каталог товарів</div>
                            <div class="text-muted" style="font-size:.7rem;letter-spacing:.06em;font-weight:600">ОБЕРІТЬ ПОЗИЦІЇ</div>
                        </div>
                        <button type="button" class="ib-modal-close" onclick="closeProductModal()"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <div id="prod-toolbar" style="padding:12px 18px; background:#f7f8fa; border-bottom:1px solid #eef0f3">
                        <div class="d-flex gap-2">
                            <select id="prod-cat" class="form-select form-select-sm" style="max-width:230px; border-radius:10px" onchange="prodCatId=this.value;loadProducts()">
                                <option value="">Всі категорії</option>
                            </select>
                            <input id="prod-search" class="form-control form-control-sm" style="border-radius:10px" placeholder="Пошук товару…" oninput="prodSearchInput(this.value)">
                        </div>
                    </div>
                    <div class="ib-modal-body" style="background:#f7f8fa"><div id="prod-body"></div></div>
                </div>`;
            el.addEventListener('click', ev => { if (ev.target === el) closeProductModal(); });
            document.body.appendChild(el);
        }

        async function openProductModal() {
            ensureProductModal();
            document.getElementById('prod-modal').classList.remove('d-none');
            if (!prodCats) {
                try { prodCats = await (await fetch('/products/categories', { headers: { 'Accept': 'application/json' } })).json(); } catch (e) { prodCats = []; }
                const sel = document.getElementById('prod-cat');
                sel.innerHTML = '<option value="">Всі категорії</option>'
                    + (prodCats || []).map(ct => `<option value="${ct.id}">${esc(ct.name)}</option>`).join('');
            }
            loadProducts();
        }
        function closeProductModal() { document.getElementById('prod-modal')?.classList.add('d-none'); }
        function prodSearchInput(v) { prodQ = v.trim(); clearTimeout(prodQT); prodQT = setTimeout(loadProducts, 350); }

        async function loadProducts() {
            const b = document.getElementById('prod-body');
            if (b) b.innerHTML = '<div class="text-muted small p-3 text-center">Завантаження…</div>';
            try {
                const params = new URLSearchParams({ with_variants: 1, per_page: 100 });
                if (prodCatId) params.set('category', prodCatId);
                if (prodQ) params.set('q', prodQ);
                const res = await fetch('/products?' + params.toString(), { headers: { 'Accept': 'application/json' } });
                prodItems = (await res.json()).data || [];
            } catch (e) { prodItems = []; }
            prodOpen = {};
            renderProdList();
        }

        function variantCount(pid, vid) {
            const it = orderItems.find(x => x.product_id === pid && x.product_variant_id === vid);
            return it ? it.qty : 0;
        }

        function renderProdList() {
            const b = document.getElementById('prod-body');
            if (!b) return;
            if (!prodItems.length) { b.innerHTML = '<div class="text-muted small p-3 text-center">Нічого не знайдено</div>'; return; }
            b.innerHTML = prodItems.map(p => {
                const variants = (p.variants || []).filter(v => v.is_active !== false);
                const totalStock = variants.reduce((s, v) => s + (v.stock_qty || 0), 0);
                const price = parseFloat(p.sale_price || 0);
                const open = !!prodOpen[p.id];
                const rows = !open ? '' : (variants.length ? variants.map(v => {
                    const cnt = variantCount(p.id, v.id);
                    return `
                    <div class="ib-vrow">
                        <div>
                            <div class="fw-bold" style="font-size:.95rem">${esc(v.size || '—')}</div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                ${v.sku ? `<span class="ib-sku">${esc(v.sku)}</span>` : ''}
                                <span class="ib-stock">${v.stock_qty ?? 0} шт.</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="ib-price">${price.toFixed(2)} <span class="cur">UAH</span></div>
                            <button type="button" class="ib-add-btn ${cnt ? 'added' : ''}" onclick="addVariant(${p.id}, ${v.id})"><i class="bi bi-plus-lg"></i> Дод.${cnt ? ' (' + cnt + ')' : ''}</button>
                        </div>
                    </div>`;
                }).join('') : (() => {
                    const cnt = variantCount(p.id, null);
                    return `
                    <div class="ib-vrow">
                        <div class="fw-bold" style="font-size:.92rem">Без розміру</div>
                        <div class="text-end">
                            <div class="ib-price">${price.toFixed(2)} <span class="cur">UAH</span></div>
                            <button type="button" class="ib-add-btn ${cnt ? 'added' : ''}" onclick="addVariant(${p.id}, null)"><i class="bi bi-plus-lg"></i> Дод.${cnt ? ' (' + cnt + ')' : ''}</button>
                        </div>
                    </div>`;
                })());
                return `
                <div class="ib-pcard">
                    <div class="ib-prow" onclick="toggleProd(${p.id})">
                        ${p.main_photo_url ? `<img src="${esc(p.main_photo_url)}" loading="lazy">` : '<div class="ph"><i class="bi bi-image"></i></div>'}
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="ib-ptitle">${esc(p.title)}</div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                ${p.sku ? `<span class="ib-sku">${esc(p.sku)}</span>` : ''}
                                <span class="ib-stock">Всього ${totalStock} шт.</span>
                            </div>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <div class="ib-price">${price.toFixed(2)} <span class="cur">UAH</span></div>
                            <button type="button" class="ib-sizes-btn"><i class="bi bi-chevron-${open ? 'up' : 'down'}"></i> Розміри</button>
                        </div>
                    </div>
                    ${rows}
                </div>`;
            }).join('');
        }

        function toggleProd(pid) {
            prodOpen[pid] = !prodOpen[pid];
            renderProdList();
        }

        function addVariant(pid, vid) {
            const p = prodItems.find(x => x.id === pid);
            if (!p) return;
            const v = vid ? (p.variants || []).find(x => x.id === vid) : null;
            const existing = orderItems.find(x => x.product_id === pid && x.product_variant_id === (v ? v.id : null));
            if (existing) existing.qty++;
            else orderItems.push({
                product_id: p.id,
                product_variant_id: v ? v.id : null,
                title: p.title,
                sku: (v && v.sku) || p.sku || null,
                size: v ? v.size : null,
                qty: 1,
                price: parseFloat(p.sale_price) || 0,
                photo: p.main_photo_url || null,
            });
            renderProdList();
            renderInfo(currentConv);
        }

        function ctxHtml(c, out) {
            if (!c) return '';
            const img = c.image ? `<img src="${esc(c.image)}" loading="lazy" onerror="this.remove()">` : '';
            if (c.type === 'reply') {
                return `<div class="ib-row ${out ? 'out' : ''}"><div class="ib-ctx">${img}<span><i class="bi bi-reply-fill me-1"></i>У відповідь на: ${esc(c.text || '')}</span></div></div>`;
            }
            return `<div class="ib-row ${out ? 'out' : ''}"><div class="ib-ctx">${img}<span><i class="bi bi-pin-angle me-1"></i>${esc(c.label || 'Контекст')}</span></div></div>`;
        }

        function renderMessages(messages, opts = {}) {
            const box = document.getElementById('thread-messages');
            // Чи був користувач унизу ПЕРЕД оновленням (міряємо до зміни вмісту).
            const wasAtBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
            box.innerHTML = messages.map((m, i) => {
                const out = m.direction === 'out';
                const atts = (m.attachments || []).map(a => a.url ? `<img src="${esc(a.url)}">` : '').join('');
                const media = atts && !m.text ? ' media' : '';
                const next = messages[i + 1];
                const showTime = !next || next.direction !== m.direction;
                const time = showTime ? `<div class="ib-time-mini ${out ? 'out' : ''}">${esc(m.sent_at_human || '')}</div>` : '';
                const aiMark = m.sender === 'ai' ? '<i class="bi bi-stars me-1" style="font-size:.72rem;opacity:.85" title="Відповів AI-агент"></i>' : '';
                return `${ctxHtml(m.context, out)}<div class="ib-row ${out ? 'out' : ''}"><div class="ib-bub ${out ? 'out' : 'in'}${media}">${aiMark}${m.text ? esc(m.text) : ''}${atts}</div></div>${time}`;
            }).join('');
            appendPending();
            // Скролимо вниз лише при відкритті чату / після відправки, або якщо користувач і так був унизу.
            if (opts.forceBottom || wasAtBottom) {
                box.scrollTop = box.scrollHeight;
            }
        }

        function showErr(m) { const e = document.getElementById('reply-error'); e.textContent = m; e.classList.remove('d-none'); }

        // Оптимістична відправка тексту: бульбашка зʼявляється миттєво,
        // POST іде у фоні; при помилці — позначка «не надіслано».
        let optSeq = 0, pendingTexts = [];

        function optBubbleHtml(p) {
            return `<div class="ib-row out" data-opt="${p.key}"><div class="ib-bub out">${esc(p.text)}</div></div>`
                + (p.failed ? '<div class="ib-time-mini out" style="color:#dc3545">не надіслано — спробуйте ще раз</div>' : '');
        }

        function appendPending() {
            const mine = pendingTexts.filter(p => p.convId === activeId);
            if (!mine.length) return;
            const box = document.getElementById('thread-messages');
            mine.forEach(p => box.insertAdjacentHTML('beforeend', optBubbleHtml(p)));
        }

        function sendTextOptimistic(text, convId) {
            if (!convId || !text) return;
            const p = { key: ++optSeq, convId, text, failed: false };
            pendingTexts.push(p);
            if (convId === activeId) {
                const box = document.getElementById('thread-messages');
                box.insertAdjacentHTML('beforeend', optBubbleHtml(p));
                box.scrollTop = box.scrollHeight;
            }
            fetch(`/api/inbox/conversations/${convId}/send`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ text })
            })
                .then(r => r.json().then(d => ({ ok: r.ok && d.ok, d })).catch(() => ({ ok: false, d: {} })))
                .catch(() => ({ ok: false, d: {} }))
                .then(({ ok, d }) => {
                    if (ok) {
                        pendingTexts = pendingTexts.filter(x => x.key !== p.key);
                        loadConversations();
                        return;
                    }
                    p.failed = true;
                    const el = document.querySelector(`[data-opt="${p.key}"]`);
                    if (el && p.convId === activeId) {
                        el.insertAdjacentHTML('afterend', '<div class="ib-time-mini out" style="color:#dc3545">не надіслано — спробуйте ще раз</div>');
                        el.parentElement.scrollTop = el.parentElement.scrollHeight;
                    }
                });
        }

        let staged = [], sendingNow = false;
        const replyTa = document.getElementById('reply-input');
        function autoGrow() {
            replyTa.style.height = 'auto';
            replyTa.style.height = Math.min(replyTa.scrollHeight, 200) + 'px';
            const has = replyTa.value.trim().length > 0 || staged.length > 0 || sendingNow;
            document.getElementById('like-btn').classList.toggle('d-none', has);
            document.getElementById('send-btn').classList.toggle('d-none', !has);
        }
        replyTa.addEventListener('input', autoGrow);
        replyTa.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('reply-form').requestSubmit();
            }
        });

        document.getElementById('reply-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (sendingNow) return;
            const input = document.getElementById('reply-input');
            const text = input.value.trim();
            if (!staged.length && !text) return;
            document.getElementById('reply-error').classList.add('d-none');
            const convId = activeId;

            // Лише текст — миттєва бульбашка, нічого не блокуємо.
            if (!staged.length) {
                input.value = '';
                sendTextOptimistic(text, convId);
                input.focus(); autoGrow();
                return;
            }

            // Є вкладення — черга зі спінерами, кнопка залочена до кінця.
            sendingNow = true;
            input.disabled = true;
            const sBtn = document.getElementById('send-btn');
            sBtn.disabled = true; sBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            let okAll = true;
            while (staged.length) {
                const item = staged[0];
                item.sending = true; renderStaged();
                const ok = item.kind === 'file'
                    ? await uploadAndSendFile(item.file, convId)
                    : await sendGalleryImage(item.id, convId);
                if (!ok) { item.sending = false; okAll = false; renderStaged(); break; }
                staged.shift(); renderStaged();
            }
            if (okAll && convId === activeId) { await openConversation(convId); }
            if (okAll && text) { sendTextOptimistic(text, convId); input.value = ''; }
            sendingNow = false;
            input.disabled = false; sBtn.disabled = false; sBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
            input.focus(); autoGrow();
        });

        function sendLike() { if (!activeId || sendingNow) return; sendTextOptimistic('👍', activeId); }

        const EMOJIS = ['😊','😂','❤️','👍','🙏','🔥','😍','🎉','👌','✅','🤝','😉','🙂','😅','💪','👋','📦','🚚','💰','❓','😎','🤔','🥰','👏','💯','🙌','😢','🤗'];
        function toggleEmoji() {
            document.getElementById('tpl-pop').classList.add('d-none');
            const p = document.getElementById('emoji-pop');
            if (!p.dataset.filled) {
                p.querySelector('.ib-emoji-grid').innerHTML = EMOJIS.map(e => `<button type="button" onclick="insertText('${e}')">${e}</button>`).join('');
                p.dataset.filled = '1';
            }
            p.classList.toggle('d-none');
        }
        function insertText(t) { const i = document.getElementById('reply-input'); i.value += t; i.focus(); autoGrow(); }

        async function toggleTpl() {
            document.getElementById('emoji-pop').classList.add('d-none');
            const p = document.getElementById('tpl-pop');
            p.classList.toggle('d-none');
            if (!tplLoaded && !p.classList.contains('d-none')) {
                try {
                    const res = await fetch('{{ route('templates.list') }}', { headers: { 'Accept': 'application/json' } });
                    tplItems = (await res.json()).data || [];
                    p.querySelector('.ib-tpl').innerHTML = tplItems.length
                        ? tplItems.map((t, i) => `<div class="ib-tpl-item" onclick="useTpl(${i})"><div class="tt">${esc(t.title)}</div><div class="bd text-truncate">${esc(t.content)}</div></div>`).join('')
                        : '<div class="text-muted small p-2">Немає шаблонів. Додай у розділі «Шаблони».</div>';
                    tplLoaded = true;
                } catch (e) { p.querySelector('.ib-tpl').innerHTML = '<div class="text-danger small p-2">Помилка завантаження</div>'; }
            }
        }
        function useTpl(i) {
            document.getElementById('reply-input').value = tplItems[i].content;
            document.getElementById('tpl-pop').classList.add('d-none');
            document.getElementById('reply-input').focus();
            autoGrow();
        }

        function renderStaged() {
            const box = document.getElementById('attach-preview');
            if (!staged.length) { box.classList.add('d-none'); box.innerHTML = ''; autoGrow(); return; }
            box.classList.remove('d-none');
            box.innerHTML = staged.map((s, i) => `
                <div class="ib-attach-item">
                    ${s.url ? `<img src="${s.url}">` : '<span class="ib-attach-file"><i class="bi bi-file-earmark-text"></i></span>'}
                    ${s.sending
                        ? '<span class="ib-attach-spin"><span class="spinner-border spinner-border-sm"></span></span>'
                        : `<button type="button" class="ib-attach-x" onclick="removeStaged(${i})" title="Прибрати"><i class="bi bi-x"></i></button>`}
                </div>`).join('');
            autoGrow();
        }

        function removeStaged(i) {
            staged.splice(i, 1);
            renderStaged();
        }

        function stageFile(input) {
            if (!input.files.length) return;
            for (const f of input.files) {
                const item = { kind: 'file', file: f, url: null };
                staged.push(item);
                if (f.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = ev => { item.url = ev.target.result; renderStaged(); };
                    reader.readAsDataURL(f);
                }
            }
            document.getElementById('reply-error').classList.add('d-none');
            input.value = '';
            renderStaged();
        }

        async function uploadAndSendFile(file, convId) {
            const fd = new FormData();
            fd.append('file', file);
            try {
                const res = await fetch(`/api/inbox/conversations/${convId}/send-attachment`, {
                    method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'Не вдалося надіслати');
                return true;
            } catch (err) { showErr(err.message); return false; }
        }

        async function sendGalleryImage(id, convId) {
            try {
                const res = await fetch(`/api/inbox/conversations/${convId}/send-gallery`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || 'Не вдалося надіслати');
                return true;
            } catch (err) { showErr(err.message); return false; }
        }

        // --- Галерея у модальному вікні з мультивибором ---
        let galleryItems = [], gallerySelected = [];
        function ensureGalleryModal() {
            if (document.getElementById('gallery-modal')) return;
            const el = document.createElement('div');
            el.id = 'gallery-modal';
            el.className = 'ib-modal d-none';
            el.innerHTML = `
                <div class="ib-modal-card">
                    <div class="ib-modal-head">
                        <div class="ib-modal-title">Галерея — оберіть фото</div>
                        <button type="button" class="ib-modal-close" onclick="closeGalleryModal()"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <div class="ib-modal-body"><div id="gallery-modal-grid" class="ib-mgrid"></div></div>
                    <div class="ib-modal-foot">
                        <div class="text-muted small" id="gallery-count">Нічого не вибрано</div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light btn-sm" onclick="closeGalleryModal()">Скасувати</button>
                            <button type="button" class="btn btn-primary btn-sm" id="gallery-send-btn" onclick="addSelectedGallery()" disabled>Додати</button>
                        </div>
                    </div>
                </div>`;
            el.addEventListener('click', ev => { if (ev.target === el) closeGalleryModal(); });
            document.body.appendChild(el);
        }
        async function openGalleryModal() {
            if (!activeId) { showErr('Спершу відкрийте діалог'); return; }
            ensureGalleryModal();
            gallerySelected = [];
            updateGalleryFooter();
            document.getElementById('gallery-modal').classList.remove('d-none');
            const grid = document.getElementById('gallery-modal-grid');
            grid.innerHTML = '<div class="text-muted small p-3" style="grid-column:1/-1">Завантаження…</div>';
            try {
                const res = await fetch('/api/saved-files', { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                galleryItems = (json.data || json || []).filter(f => f.type === 'image' && f.url);
                grid.innerHTML = galleryItems.length
                    ? galleryItems.map(it => `<div class="ib-mtile" data-id="${it.id}" onclick="toggleGalleryTile(${it.id})"><img src="${esc(it.url)}" loading="lazy"><span class="num"></span></div>`).join('')
                    : '<div class="text-muted small p-3" style="grid-column:1/-1">Галерея порожня. Додайте фото в розділі «Галерея».</div>';
            } catch (e) { grid.innerHTML = '<div class="text-danger small p-3" style="grid-column:1/-1">Помилка завантаження</div>'; }
        }
        function closeGalleryModal() { document.getElementById('gallery-modal')?.classList.add('d-none'); }
        function toggleGalleryTile(id) {
            const i = gallerySelected.indexOf(id);
            if (i >= 0) gallerySelected.splice(i, 1); else gallerySelected.push(id);
            document.querySelectorAll('#gallery-modal-grid .ib-mtile').forEach(tile => {
                const pos = gallerySelected.indexOf(Number(tile.dataset.id));
                tile.classList.toggle('sel', pos >= 0);
                tile.querySelector('.num').textContent = pos >= 0 ? (pos + 1) : '';
            });
            updateGalleryFooter();
        }
        function updateGalleryFooter() {
            const n = gallerySelected.length;
            const c = document.getElementById('gallery-count');
            if (c) c.textContent = n ? ('Вибрано: ' + n) : 'Нічого не вибрано';
            const btn = document.getElementById('gallery-send-btn');
            if (btn) { btn.disabled = n === 0; btn.textContent = n ? ('Додати (' + n + ')') : 'Додати'; }
        }
        function addSelectedGallery() {
            if (!gallerySelected.length) return;
            for (const id of gallerySelected) {
                const it = galleryItems.find(g => g.id === id);
                if (it) staged.push({ kind: 'gallery', id: it.id, url: it.url });
            }
            closeGalleryModal();
            renderStaged();
        }

        async function syncHistory(btn) {
            const prev = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            try { await fetch('{{ route('inbox.sync') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } }); await loadConversations(); }
            catch (e) {} finally { btn.disabled = false; btn.innerHTML = prev; }
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.ib-tool') && !e.target.closest('.ib-pop')) {
                document.getElementById('emoji-pop')?.classList.add('d-none');
                document.getElementById('tpl-pop')?.classList.add('d-none');
            }
            if (!e.target.closest('.ib-thbtn') && !e.target.closest('.ib-th-pop')) {
                document.getElementById('th-menu')?.classList.add('d-none');
            }
            if (!e.target.closest('.ib-np-list') && e.target.id !== 'np-city' && e.target.id !== 'np-wh') {
                document.querySelectorAll('.ib-np-list').forEach(l => l.classList.add('d-none'));
            }
        });

        document.getElementById('conv-list').addEventListener('scroll', function () {
            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 120) loadMoreConvs();
        });

        // --- Вкладка «Коментарі» ---
        let ibTab = 'msgs', cmFilter = 'all', cmItems = [], cmOpenDm = null, cmOnlyNew = false;

        async function ensureTpl() {
            if (tplLoaded) return;
            try {
                const res = await fetch('{{ route('templates.list') }}', { headers: { 'Accept': 'application/json' } });
                tplItems = (await res.json()).data || [];
                tplLoaded = true;
            } catch (e) {}
        }

        function cmTplOptions() {
            return '<option value="">шаблон…</option>' + tplItems.map((t, i) => `<option value="${i}">${esc(t.title)}</option>`).join('');
        }

        async function openCommentConversation(id) {
            try {
                const res = await fetch(`/api/inbox/comments/${id}/conversation`, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.conversation_id) {
                    setTab('msgs');
                    openConversation(data.conversation_id);
                } else {
                    alert('Діалог ще створюється (відповідь у дорозі) — спробуй за хвилинку.');
                }
            } catch (e) {}
            return false;
        }

        function setTab(t) {
            ibTab = t;
            document.getElementById('tab-msgs').classList.toggle('active', t === 'msgs');
            document.getElementById('tab-comments').classList.toggle('active', t === 'comments');
            document.getElementById('pane-msgs').classList.toggle('d-none', t !== 'msgs');
            document.getElementById('pane-comments').classList.toggle('d-none', t !== 'comments');
            document.getElementById('conv-list').classList.toggle('d-none', t !== 'msgs');
            document.getElementById('comments-list').classList.toggle('d-none', t !== 'comments');
            if (t === 'comments') loadComments();
        }

        function setCmFilter(f, btn) {
            cmFilter = f;
            document.querySelectorAll('#pane-comments .ib-chip').forEach(c => c.classList.toggle('active', c === btn));
            loadComments();
        }

        async function loadComments() {
            try {
                const res = await fetch(`/api/inbox/comments${cmFilter !== 'all' ? ('?channel=' + cmFilter) : ''}`, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                cmItems = data.items || [];
                const badge = document.getElementById('cm-badge');
                badge.textContent = data.new_count;
                badge.classList.toggle('d-none', !data.new_count);
                if (ibTab === 'comments') renderComments();
            } catch (e) {}
        }

        function renderComments() {
            const box = document.getElementById('comments-list');
            // Пол перемальовує список кожні 6с — не губимо текст, який людина набирає
            const prevEl = cmOpenDm !== null ? document.getElementById('dm-input-' + cmOpenDm) : null;
            const prevVal = prevEl ? prevEl.value : null;
            const prevFocus = prevEl && document.activeElement === prevEl;
            if (!cmItems.length) {
                box.innerHTML = '<div class="ib-empty"><i class="bi bi-chat-square-quote d-block mb-2"></i>Коментарів поки немає</div>';
                return;
            }
            const list = cmOnlyNew ? cmItems.filter(c => c.status !== 'dm_sent') : cmItems;
            box.innerHTML = list.map(c => {
                const post = (c.post_image || c.post_excerpt)
                    ? `<div class="post">${c.post_image ? `<img src="${esc(c.post_image)}" loading="lazy">` : ''}<span>${esc(c.post_excerpt || 'Пост')}</span></div>`
                    : '';
                const dmForm = `<form class="dm-form" onsubmit="return sendCmDm(event, ${c.id})">
                               <input id="dm-input-${c.id}" placeholder="Доброго дня! …" autocomplete="off">
                               <select class="form-select form-select-sm" style="max-width:110px" onchange="if(this.value!==''){document.getElementById('dm-input-${c.id}').value=tplItems[this.value].content;this.value='';}">${cmTplOptions()}</select>
                               <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send"></i></button>
                           </form>`;
                let action;
                if (c.status === 'dm_sent') {
                    action = `<a href="#" class="ib-cm-sent" onclick="return openCommentConversation(${c.id})" title="Відкрити діалог з цією людиною">
                        <i class="bi bi-check2-all me-1"></i>Надіслано в директ${c.matched_group ? ' · ШІ: ' + esc(c.matched_group) : ''} <i class="bi bi-box-arrow-up-right"></i></a>`;
                } else if (cmOpenDm === c.id) {
                    action = dmForm;
                } else {
                    const failed = c.status === 'dm_failed' ? '<span class="text-danger small me-2">не надіслалось</span>' : '';
                    action = `${failed}<button class="btn btn-sm btn-outline-primary" onclick="cmOpenDm=${c.id};ensureTpl().then(renderComments);setTimeout(()=>document.getElementById('dm-input-${c.id}')?.focus(),80)"><i class="bi bi-send me-1"></i>Написати в директ</button>`;
                }
                return `<div class="ib-cm">
                    <div class="hd">${chIcon(c.channel)}<span class="nm">${esc(c.from_name)}</span><span class="tm">${esc(c.at_human || '')}</span>
                        <button class="del" title="Видалити коментар з CRM" onclick="deleteComment(${c.id})"><i class="bi bi-trash"></i></button>
                    </div>
                    <div class="tx">${esc(c.text || '')}</div>
                    ${post}
                    <div class="actions">${action}</div>
                </div>`;
            }).join('');

            // Відновлюємо набраний текст і курсор після перемальовки
            if (cmOpenDm !== null && prevVal !== null) {
                const el = document.getElementById('dm-input-' + cmOpenDm);
                if (el) {
                    el.value = prevVal;
                    if (prevFocus) { el.focus(); el.setSelectionRange(prevVal.length, prevVal.length); }
                }
            }
        }

        async function deleteComment(id) {
            if (!confirm('Видалити цей коментар з CRM? (В Instagram/Facebook він залишиться, якщо там не видалений)')) return;
            try {
                await fetch(`/api/inbox/comments/${id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                });
                loadComments();
            } catch (e) { alert('Не вдалося видалити'); }
        }

        async function sendCmDm(e, id) {
            e.preventDefault();
            const input = document.getElementById('dm-input-' + id);
            const text = (input?.value || '').trim();
            if (!text) return false;
            input.disabled = true;
            try {
                const res = await fetch(`/api/inbox/comments/${id}/dm`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    body: JSON.stringify({ text }),
                });
                const data = await res.json();
                if (!data.ok) { alert(data.error || 'Не вдалося надіслати'); input.disabled = false; return false; }
                cmOpenDm = null;
                loadComments();
            } catch (err) {
                alert('Помилка відправки');
                input.disabled = false;
            }
            return false;
        }

        loadChatStatuses().then(loadConversations);
        loadComments();
        setInterval(() => {
            loadConversations();
            loadComments();
            if (activeId) {
                fetch(`/api/inbox/conversations/${activeId}/messages`, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json()).then(d => renderMessages(d.messages)).catch(() => {});
            }
        }, 6000);
    </script>
    @endpush
</x-app-layout>
