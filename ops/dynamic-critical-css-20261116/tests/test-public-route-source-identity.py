import importlib.util,pathlib,sys
p=pathlib.Path(sys.argv[1])/'public-route-certificate.py'
sp=importlib.util.spec_from_file_location('proof',p)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
checks=[]
def ok(label,condition):
 assert condition,label
 checks.append(label);print('PASS',label)
def bound(typ,id,classes):
 return m.verify_queried_object({'type':typ,'id':id},'<html><body class="'+classes+'">')
def denied(typ,id,classes):
 try:bound(typ,id,classes)
 except ValueError:return True
 return False
ok('public page object bound by page-id',bound('page',68813,'page page-id-68813 wp-theme-astra'))
ok('tour object bound by postid',bound('tours-and-activities',60433,'single single-tours-and-activities postid-60433'))
ok('ship object bound by postid',bound('properties',46502,'single single-properties postid-46502'))
ok('taxonomy term ID bound to actual source',bound('tour-types',625,'archive tax-tour-types term-combined-tours term-625'))
ok('all public archive types require actual body type',bound('archive:tours-and-activities',0,'archive post-type-archive post-type-archive-tours-and-activities'))
ok('core Uncategorized category ID bound by native class',bound('category',1,'archive category category-uncategorized category-1'))
ok('core Interests category ID bound by native class',bound('category',2943,'archive category category-interests category-2943'))
ok('wrong core category ID rejected',denied('category',2943,'archive category category-interests category-1'))
ok('core category cannot be impersonated by custom taxonomy term',denied('category',2943,'archive category term-2943'))
ok('numeric core category prefix cannot impersonate ID',denied('category',2943,'archive category category-29430'))
ok('wrong taxonomy ID rejected',denied('tour-types',626,'archive tax-tour-types term-combined-tours term-625'))
ok('taxonomy cannot impersonate post ID',denied('tour-types',625,'single postid-625'))
ok('post cannot impersonate term',denied('properties',46502,'archive term-46502'))
ok('different archive type rejected',denied('archive:properties',0,'archive post-type-archive-tours-and-activities'))
ok('archive cannot use invented object ID',denied('archive:tours-and-activities',2,'archive post-type-archive-tours-and-activities'))
print('PASS',len(checks),'/',len(checks),'canonical WordPress public route identity')
