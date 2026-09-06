(function(){
'use strict';
function textFromTarget(id){var el=document.getElementById(id);return el?String(el.textContent||'').trim():'';}
function copyText(text,button){
    if(!text){return;}
    var done=function(){if(!button){return;}var original=button.textContent;button.textContent='Copied';button.disabled=true;window.setTimeout(function(){button.textContent=original;button.disabled=false;},1200);};
    if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(done).catch(function(){fallback(text,done);});return;}
    fallback(text,done);
}
function fallback(text,done){var area=document.createElement('textarea');area.value=text;area.setAttribute('readonly','readonly');area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();try{document.execCommand('copy');done();}catch(e){}document.body.removeChild(area);}
document.addEventListener('click',function(event){var button=event.target.closest?event.target.closest('.etg-copy-button'):null;if(!button){return;}event.preventDefault();copyText(textFromTarget(button.getAttribute('data-etg-copy-target')),button);});
var search=document.getElementById('etg-guide-token-search');var table=document.getElementById('etg-guide-token-table');
if(search&&table){search.addEventListener('input',function(){var needle=String(search.value||'').trim().toLowerCase();Array.prototype.forEach.call(table.querySelectorAll('tbody tr[data-etg-search]'),function(row){row.hidden=needle!==''&&String(row.getAttribute('data-etg-search')||'').indexOf(needle)===-1;});});}
})();
