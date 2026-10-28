(function(){
'use strict';
var map={
    listing_field:['role','field','aggregate','limit'],
    listing_meta:['role','meta','aggregate','limit'],
    term_field:['role','field','aggregate','limit'],
    term_meta:['role','meta','aggregate','limit'],
    context:['field','aggregate','limit'],
    repeater:['role','field','meta','aggregate','limit'],
    query:['field','query','aggregate','limit'],
    relation:['field','relation','direction','aggregate','limit'],
    relation_meta:['meta','relation','direction','aggregate','limit']
};
function rows(){return Array.prototype.slice.call(document.querySelectorAll('#etg-source-builder .etg-source-row'));}
function updateRow(row){var type=row.querySelector('[name="source_type[]"]');if(!type){return;}var visible=map[type.value]||['role','field','meta','query','relation','direction','aggregate','limit'];Array.prototype.slice.call(row.querySelectorAll('[data-etg-source-field]')).forEach(function(cell){var key=cell.getAttribute('data-etg-source-field');cell.hidden=visible.indexOf(key)===-1;});}
function clearRow(row){Array.prototype.slice.call(row.querySelectorAll('input,select')).forEach(function(el){if(el.name==='source_type[]'){el.value='listing_field';}else if(el.name==='source_direction[]'){el.value='children';}else if(el.name==='source_aggregate[]'){el.value='first';}else if(el.name==='source_limit[]'){el.value='20';}else if(el.tagName==='SELECT'){el.selectedIndex=0;}else{el.value='';}});updateRow(row);}
function firstHidden(){return rows().filter(function(row){return row.hidden||row.style.display==='none';})[0]||null;}
function addRow(){var row=firstHidden();if(!row){return;}row.hidden=false;row.style.display='';updateRow(row);var input=row.querySelector('input');if(input){input.focus();}}
function preset(name){var row=firstHidden()||rows().filter(function(r){var a=r.querySelector('[name="source_alias[]"]');return a&&!a.value;})[0];if(!row){return;}row.hidden=false;row.style.display='';clearRow(row);var set=function(field,value){var el=row.querySelector('[name="'+field+'[]"]');if(el){el.value=value;}};
    if(name==='listing-image'){set('source_alias','listing_image');set('source_type','listing_field');set('source_field','featured_image_id');set('source_aggregate','image');}
    if(name==='repeater-gallery'){set('source_alias','repeater_gallery');set('source_type','repeater');set('source_meta_key','gallery');set('source_field','image');set('source_aggregate','gallery');}
    if(name==='query-gallery'){set('source_alias','query_gallery');set('source_type','query');set('source_field','featured_image_id');set('source_aggregate','gallery');}
    if(name==='related-cards'){set('source_alias','related_items');set('source_type','relation');set('source_field','title');set('source_aggregate','json');}
    updateRow(row);
}
document.addEventListener('DOMContentLoaded',function(){
    rows().forEach(function(row){var select=row.querySelector('[name="source_type[]"]');if(select){select.addEventListener('change',function(){updateRow(row);});}var remove=row.querySelector('[data-etg-remove-source]');if(remove){remove.addEventListener('click',function(){clearRow(row);row.hidden=true;row.style.display='none';});}updateRow(row);});
    var add=document.getElementById('etg-add-source');if(add){add.addEventListener('click',addRow);}Array.prototype.slice.call(document.querySelectorAll('[data-etg-source-preset]')).forEach(function(button){button.addEventListener('click',function(){preset(button.getAttribute('data-etg-source-preset'));});});
});
})();
