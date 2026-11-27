jQuery(function($){
  const T=UAMM.i18n||{};
  const notice=(msg,bad)=>{const el=$('#uamm-notice');el.text(msg).toggleClass('is-error',!!bad).addClass('is-visible');setTimeout(()=>el.removeClass('is-visible'),6000);};
  const selected=()=>$('.uamm-check:checked').map(function(){return parseInt(this.value,10)}).get();
  $('#uamm-select-all').on('change',function(){$('.uamm-check').prop('checked',this.checked);});
  $('#uamm-create-folder').on('click',function(e){e.preventDefault();const name=$('#uamm-folder-name').val().trim();if(!name){notice(T.enterFolder||'Вкажіть назву папки.',true);return;}request('create_folder',{name,parent:$('#uamm-folder-parent').val()||0}).done(r=>{notice(r.data?.message||T.done||'Готово');if(r.success)setTimeout(()=>location.reload(),700);});});
  $('#uamm-create-templates').on('click',function(e){e.preventDefault();const b=$(this);b.prop('disabled',true);request('create_templates',{}).done(r=>{notice(r.data?.message||T.done||'Готово',!r.success);if(r.success)setTimeout(()=>location.reload(),800);}).always(()=>b.prop('disabled',false));});
  $('[data-action]').on('click',function(e){e.preventDefault();const btn=$(this),action=btn.data('action');if(action==='delete'&&!confirm(UAMM.confirmDelete))return;const payload={id:btn.data('id'),ids:action==='quarantine'?selected():[]};if(action==='rename'||action==='rename_folder'){const name=prompt(action==='rename_folder'?(T.newFolderName||'Нова назва папки:'):(T.newFileName||'Нова назва файлу (з розширенням або без):'));if(!name)return;payload.name=name;}request(action,payload).done(r=>{notice(r.data?.message||T.done||'Готово',!r.success);if(r.success)setTimeout(()=>location.reload(),800);});});
  $('#uamm-move').on('click',function(){const folder=$('#uamm-move-folder').val(),ids=selected();if(!ids.length||!folder){notice(T.selectFilesFolder||'Оберіть файли та папку.',true);return;}request('move',{folder,ids}).done(r=>{notice(r.data?.message||'Готово',!r.success);if(r.success)setTimeout(()=>location.reload(),800);});});
  $('#uamm-scan-unused').on('click',function(){const b=$(this);b.prop('disabled',true).text(UAMM.working);request('scan_unused',{}).done(r=>{const out=$('#uamm-scan-result');out.empty();if(!r.success){out.text(r.data?.message||T.moveError||'Помилка');return;}out.append($('<p>').text(r.data.message));if(r.data.items?.length){const ul=$('<ul>');r.data.items.forEach(x=>ul.append($('<li>').text(x.title+' — '+x.url)));out.append(ul);} }).always(()=>b.prop('disabled',false).text(T.scanUnused||'Знайти можливі невикористовувані'));});
  $('#uamm-scan-duplicates').on('click',function(e){e.preventDefault();const b=$(this),out=$('#uamm-duplicates-result');b.prop('disabled',true);out.empty().text(T.hashScan||'Перевірка файлів за SHA-256…');request('scan_duplicates',{}).done(r=>{out.empty();if(!r.success){out.text(r.data?.message||T.scanError||'Помилка сканування');return;}out.append($('<p>').text(r.data.message));const groups=r.data.groups||[];if(!groups.length){out.append($('<p>').text(T.noDuplicates||'Однакових зображень не знайдено.'));return;}const list=$('<ol>');groups.forEach(g=>{const items=g.items||[],main=items[0],dupes=items.slice(1).map(x=>x.id);const li=$('<li>');li.append($('<p>').text((T.primary||'Основний файл: ')+main.file+' (ID '+main.id+'). '+(T.duplicates||'Дублікати: ')+items.slice(1).map(x=>x.file+' (ID '+x.id+')').join(', ')));if(dupes.length)li.append($('<button type="button" class="button uamm-quarantine-dupes">').text((T.quarantineDuplicates||'Перемістити дублікати до карантину')).attr('data-ids',dupes.join(',')));list.append(li);});out.append(list);}).always(()=>b.prop('disabled',false));});
  $(document).on('click','.uamm-quarantine-dupes',function(){const ids=String($(this).attr('data-ids')||'').split(',').map(Number).filter(Boolean);if(!ids.length||!confirm((T.confirmDuplicates||'Перемістити ці дублікати до кошика WordPress? Перевірте, що вони не потрібні окремо.')))return;const b=$(this);b.prop('disabled',true);request('quarantine',{ids}).done(r=>{notice(r.data?.message||'Готово',!r.success);if(r.success)b.closest('li').remove();}).always(()=>b.prop('disabled',false));});
  if($('#uamm-optimizer-status').length){request('optimizer_status',{}).done(r=>$('#uamm-optimizer-status').text(r.data?.message||T.optimizerUnavailable||'Статус недоступний'));}
  $('#uamm-optimize-start').on('click',function(e){
    e.preventDefault();
    if(!confirm(T.confirmOptimize||'Перед оптимізацією створіть ZIP-резервну копію uploads. Продовжити?'))return;
    const button=$(this);button.prop('disabled',true).text(T.prepare||'Підготовка…');
    $('#uamm-optimize-progress').prop('hidden',false);$('#uamm-progress-bar').css('width','0%');$('#uamm-progress-text').text(T.prepareFiles||'Підготовка списку файлів…');$('#uamm-optimize-stats').empty();
    request('optimize_start',{}).done(r=>{
      if(!r.success){$('#uamm-progress-text').text(r.data?.message||T.startFailed||'Не вдалося запустити оптимізацію');button.prop('disabled',false).text(T.startOptimize||'Запустити lossless-оптимізацію');return;}
      const before=Number(r.data.bytes_before||0),total=Number(r.data.total||0);
      $('#uamm-progress-text').text('0 / '+total+' '+(T.fileCount||'файлів'));
      const step=offset=>request('optimize_step',{offset}).done(x=>{
        if(!x.success){$('#uamm-progress-text').text(x.data?.message||T.optimizationError||'Помилка оптимізації');button.prop('disabled',false).text(T.startOptimize||'Запустити lossless-оптимізацію');return;}
        const p=Number(x.data.percent||0),saved=Number(x.data.saved||0);
        $('#uamm-progress-bar').css('width',p+'%');
        $('#uamm-progress-text').text(x.data.processed+' / '+x.data.total+' '+(T.fileCount||'файлів')+' · '+p+'%');
        if(x.data.done){
          $('#uamm-progress-text').text(x.data.message);
          $('#uamm-optimize-stats').text((T.before||'Було: ')+formatBytes(before)+' · '+(T.after||'Стало: ')+formatBytes(Math.max(0,before-saved))+' · '+(T.saved||'Заощаджено: ')+formatBytes(saved));
          button.prop('disabled',false).text('Запустити lossless-оптимізацію');
        }else step(x.data.next);
      }).fail(()=>{button.prop('disabled',false).text('Запустити lossless-оптимізацію');$('#uamm-progress-text').text((T.connectionError||'Помилка зв’язку із сервером. Запустіть оптимізацію повторно.'));});
      step(0);
    }).fail(()=>{button.prop('disabled',false).text('Запустити lossless-оптимізацію');});
  });
  function formatBytes(bytes){if(!bytes)return '0 '+(T.bytes||'Б');const units=[T.bytes||'Б',T.kb||'КБ',T.mb||'МБ',T.gb||'ГБ'];let i=0,n=bytes;while(n>=1024&&i<units.length-1){n/=1024;i++;}return n.toFixed(2)+' '+units[i];}
  function request(action,data){return $.post(UAMM.ajax,Object.assign({action:'uamm_action',do:action,nonce:UAMM.nonce},data)).fail(xhr=>notice(xhr.responseJSON?.data?.message||T.requestError||'Помилка запиту.',true));}
});