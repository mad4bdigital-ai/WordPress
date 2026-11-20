(function(wp){
 'use strict';
 if(!wp||!wp.plugins||!wp.editPost||!wp.data||!wp.element||!wp.components)return;
 var settings=window.sharedPackageLevelEditor||{};
 if(!settings || !Array.isArray(settings.options))return;
 var el=wp.element.createElement;
 var PluginDocumentSettingPanel=wp.editPost.PluginDocumentSettingPanel;
 var CheckboxControl=wp.components.CheckboxControl,SelectControl=wp.components.SelectControl,
  Notice=wp.components.Notice;
 var editor=wp.data.useSelect, dispatch=wp.data.useDispatch;
 var key=settings.isTour?'shared-package-tour':'shared-package-rate';
 if(settings.isTour) {
  // Prevent the generic Tags panel appearing in Gutenberg. Retain the
  // taxonomy's native REST field for saving IDs via editPost.
  var hideTagEditor=function(){
   try{
    var editPost=wp.data.dispatch('core/edit-post');
    if(editPost&&typeof editPost.removeEditorPanel==='function')
     editPost.removeEditorPanel('taxonomy-panel-package_levels');
   }catch(e){}
  };
  hideTagEditor();
  if(typeof wp.domReady==='function')wp.domReady(hideTagEditor);
 }
 function ControlledPanel(){
  var state=editor(function(select){
   var e=select('core/editor');
   return {
    terms:e.getEditedPostAttribute('package_levels')||[],
    meta:e.getEditedPostAttribute('meta')||{},
    saving:e.isSavingPost&&e.isSavingPost()
   };
  },[]);
  var actions=dispatch('core/editor');
  var selected=(state.terms||[]).map(Number);
  if(settings.isTour){
   return el(PluginDocumentSettingPanel,{
    name:'shared-package-levels',title:'Allowed Package Levels',className:'shared-package-level-selector'
   },[
    el('p',{key:'intro'},'Choose approved levels for this Tour. Published Rates retain their required levels.'),
    ...settings.options.map(function(opt){
     var id=Number(opt.id),locked=(settings.required||[]).some(function(x){return Number(x)===id;});
     return el(CheckboxControl,{
      key:String(id),label:opt.name,value:id,
      checked:selected.includes(id),disabled:state.saving||locked,
      help:locked?'Used by a published Rate':undefined,
      onChange:function(checked){
       var next=checked?[...selected,id]:selected.filter(function(v){return v!==id;});
       actions.editPost({package_levels:next});
      }
     });
    })
   ]);
  }
  var value=String(state.meta.related_package_term_id||'');
  var opts=[{label:'— Select a package level —',value:''}].concat(settings.options.map(function(t){
   return {label:t.name,value:String(t.id)};
  }));
  if(value&&!opts.some(function(t){return t.value===value}))
   opts.push({label:'Previously assigned level is no longer offered',value:value,disabled:true});
  return el(PluginDocumentSettingPanel,{
   name:'shared-rate-package-level',title:'Rate Package Level',className:'shared-rate-package-level'
  },[
   el('p',{key:'intro'},'Choose exactly one level offered by the linked Tour.'),
   el(SelectControl,{
    key:'select',label:'Package Level',value:value,options:opts,
    disabled:state.saving,
    onChange:function(next){actions.editPost({meta:Object.assign({},state.meta,{
     related_package_term_id:next
    })});}
   }),
   !settings.tourId?el(Notice,{status:'warning',isDismissible:false,key:'warning'},
    'Assign the related Tour first, then reload the editor to load its allowed levels.'):null
  ]);
 }
 wp.plugins.registerPlugin(key,{
  icon:'tag',
  render:ControlledPanel
 });
})(window.wp);
