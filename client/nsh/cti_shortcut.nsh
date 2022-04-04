!macro customInstall
      SetOutPath $INSTDIR\resources\cti
      CreateShortCut "$SMPROGRAMS\サンプル CTI.lnk" "$INSTDIR\resources\cti\CTIService.exe"
      CreateShortCut "$DESKTOP\サンプル CTI.lnk" "$INSTDIR\resources\cti\CTIService.exe"
!macroend
