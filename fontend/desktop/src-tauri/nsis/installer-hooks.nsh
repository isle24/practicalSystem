!macro PracticalMigrateShortcut folder
  Push $0
  Push $1
  Push $2
  Push $3
  !insertmacro IsShortcutTarget "${folder}\Practical System.lnk" "$INSTDIR\${MAINBINARYNAME}.exe"
  Pop $0
  ${If} $0 = 1
    ${If} ${FileExists} "${folder}\${PRODUCTNAME}.lnk"
      !insertmacro IsShortcutTarget "${folder}\${PRODUCTNAME}.lnk" "$INSTDIR\${MAINBINARYNAME}.exe"
      Pop $0
      ${If} $0 = 1
        !insertmacro UnpinShortcut "${folder}\Practical System.lnk"
        Delete "${folder}\Practical System.lnk"
      ${EndIf}
    ${Else}
      Rename "${folder}\Practical System.lnk" "${folder}\${PRODUCTNAME}.lnk"
    ${EndIf}
  ${EndIf}
  Pop $3
  Pop $2
  Pop $1
  Pop $0
!macroend

!macro PracticalRemoveOldShortcut folder
  Push $0
  Push $1
  Push $2
  Push $3
  !insertmacro IsShortcutTarget "${folder}\Practical System.lnk" "$INSTDIR\${MAINBINARYNAME}.exe"
  Pop $0
  ${If} $0 = 1
    !insertmacro UnpinShortcut "${folder}\Practical System.lnk"
    Delete "${folder}\Practical System.lnk"
  ${EndIf}
  Pop $3
  Pop $2
  Pop $1
  Pop $0
!macroend

!macro NSIS_HOOK_POSTINSTALL
  !insertmacro PracticalMigrateShortcut "$SMPROGRAMS"
  ${If} $AppStartMenuFolder != ""
    !insertmacro PracticalMigrateShortcut "$SMPROGRAMS\$AppStartMenuFolder"
  ${EndIf}
  !insertmacro PracticalMigrateShortcut "$DESKTOP"
!macroend

!macro NSIS_HOOK_PREUNINSTALL
  !insertmacro CheckIfAppIsRunning "${MAINBINARYNAME}.exe" "${PRODUCTNAME}"
  !insertmacro PracticalRemoveOldShortcut "$SMPROGRAMS"
  ${If} $AppStartMenuFolder != ""
    !insertmacro PracticalRemoveOldShortcut "$SMPROGRAMS\$AppStartMenuFolder"
  ${EndIf}
  !insertmacro PracticalRemoveOldShortcut "$DESKTOP"
!macroend
