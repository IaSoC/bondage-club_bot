import os,sys,lzstring
lz_string = lzstring.LZString()

file = open(sys.argv[1],'r+')
proccesed = lz_string.decompressFromUTF16(file.read())
file.close()
os.remove(sys.argv[1])
file = open(sys.argv[1],'w+')
file.write(proccesed)
file.close()