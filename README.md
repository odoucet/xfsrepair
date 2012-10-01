xfsrepair
=========

tool to recover data when XFS metadata is corrupted.

More infos
----------

I hit a strange bug on XFS : we had a power device that failed, and when we recover, some files newly written
appeared with disk size of 0.
Using 'du -s' on folder gives us a different number though.
That's why we thought (and we were right) that data was written, but the file size itself, hold in metadata, was not written.

This bug was already reported on XFS mailing list here  :
http://oss.sgi.com/archives/xfs/2012-02/msg00517.html

If you hit this bug : Yes, you can recover the data. Procedure is very difficult if you have many files to recover.
That's why this PHP script is here for you.

PLEASE PLEASE PLEASE
Read the source code before to understand what it does. This script has been tested and worked, but I cannot be held responsible 
if you destroy some data.


My work is based on indications here : 
http://oss.sgi.com/archives/xfs/2012-02/msg00561.html

Please note that files restored will be slightly bigger because size is rounded up by block size.


Olivier

Changelog
---------

October 1st 2012
I now use sector size from xfs_db instead of stat()
