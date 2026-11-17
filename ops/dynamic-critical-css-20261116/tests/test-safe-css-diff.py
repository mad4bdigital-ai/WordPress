"""Regression: large near-identical CSS is compared linearly, not with O(n²) SequenceMatcher."""
import importlib.util, pathlib, sys, tempfile, time
D=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('diff', D/'safe-css-diff.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
count=0
def ok(label,value):
 global count
 assert value,label
 count+=1;print('PASS',label)
with tempfile.TemporaryDirectory() as td:
 root=pathlib.Path(td)
 x='a'*32+'.css';y='b'*32+'.css'
 (root/x).write_bytes(b'X'*(2*1024*1024)+b'a')
 (root/y).write_bytes(b'X'*(2*1024*1024)+b'b')
 start=time.process_time();summary=m.compare(root,x,y)
 ok('two megabytes of repeated CSS compared in linear bounded CPU',
    summary['first_difference_offset']==2*1024*1024
    and summary['mismatched_overlap_bytes']==1 and time.process_time()-start<3)
 ok('comparison is read-only and preserves source files',
    (root/x).read_bytes().endswith(b'a') and (root/y).read_bytes().endswith(b'b'))
 ok('identical assets correctly detected',m.compare(root,x,x)['identical'])
 for bad in ('../'+x,'arbitrary.css','A'*32+'.css'):
  try:m.secure_read(root,bad)
  except ValueError:ok('untrusted asset name refused',True)
  else:raise AssertionError('unsafe pathname accepted')
 (root/y).unlink();(root/y).symlink_to(root/x)
 try:m.secure_read(root,y)
 except OSError:ok('symlink refused before reading',True)
 else:raise AssertionError('symlink accepted')
 (root/y).unlink()
 with (root/y).open('wb') as f:f.truncate(m.MAX_BYTES+1)
 try:m.secure_read(root,y)
 except ValueError:ok('oversized asset refused before reading',True)
 else:raise AssertionError('oversized CSS accepted')
print(f'PASS {count}/{count} bounded CSS diagnostics')
