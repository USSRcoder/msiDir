# msiDir

Read msi installation dirs. 
Without WinAPI deps.

 - Load msi db files from mscfb container (MSCFB.php);
 - Load msi db tables (Read only). see ms orca, wine source;
 - Parse msi source and destination directories;

```
>cls & c:\old\php\php strings.php orca.msi

loaded orca.msi

loaded !_StringPool
 Code Page  : 0
 Total rows : 1656
 Ref size   : 2

loaded Directory.idb #27
loaded Component.idb #9
loaded File.idb #9

Dirs loaded
Components Checked OK

[SourceDir]\Orca\orca.dat -> [SourceDir]\Orca\orca.dat
[SourceDir]\Orca\WinNT\Orca.exe -> [SourceDir]\Orca\Orca.exe
[SourceDir]\Orca\darice.cub -> [SourceDir]\Orca\darice.cub
[SourceDir]\Orca\logo.cub -> [SourceDir]\Orca\logo.cub
[SourceDir]\Orca\XPlogo.cub -> [SourceDir]\Orca\XPlogo.cub
[SourceDir]\Orca\mergemod.cub -> [SourceDir]\Orca\mergemod.cub
[SourceDir]\Orca\WinNT\orca.chm -> [SourceDir]\Orca\orca.chm
[SourceDir]\EvalCOM\evalcom2.dll -> [SourceDir]\Microsoft Shared\MSI Tools\evalcom2.dll
[SourceDir]\MergeMod\mergemod.dll -> [SourceDir]\Microsoft Shared\MSI Tools\mergemod.dll
```

Tested with php 5.6.
