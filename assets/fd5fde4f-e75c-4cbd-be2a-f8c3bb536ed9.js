/* KAU shared scripts */
(function(){
  // reveal markers are static now (preview freezes CSS timelines); nothing to animate

  // nav shadow + dark/light swap on scroll
  var nav = document.getElementById('nav');
  if(nav){
    var dark = nav.classList.contains('on-dark');
    var logoImg = nav.querySelector('.nav-logo img');
    var onScroll = function(){
      if(dark){
        var solid = window.scrollY > window.innerHeight*0.7;
        nav.classList.toggle('on-dark', !solid);
        if(logoImg) logoImg.src = solid ? ((window.__resources && window.__resources.logoDark) || 'assets/logo-dark.svg') : ((window.__resources && window.__resources.logoLight) || 'assets/logo-light.svg');
      }
      nav.style.boxShadow = window.scrollY>10 ? '0 1px 0 rgba(0,0,0,.04)' : 'none';
    };
    window.addEventListener('scroll', onScroll, {passive:true}); onScroll();
  }

  // online shop dropdown ??click/tap toggle (hover works via CSS on desktop)
  var shop = document.getElementById('navShop');
  if(shop){
    var sbtn = shop.querySelector('.nav-shop-btn');
    sbtn.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      shop.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
      if(!shop.contains(e.target)) shop.classList.remove('open');
    });
  }

  // mobile burger -> simple anchor reveal (links already exist; toggle a panel)
  var burger = document.querySelector('.nav-burger');
  if(burger){
    burger.addEventListener('click', function(){
      var links = document.querySelector('.nav-links');
      if(!links) return;
      var open = links.style.display === 'flex';
      links.style.cssText = open ? '' : 'display:flex;position:absolute;top:var(--nav-h);left:0;right:0;flex-direction:column;background:var(--white);padding:24px var(--pad);gap:18px;border-bottom:1px solid var(--line)';
    });
  }

  // design-direction switcher (review aid ??remove before WordPress migration)
  var sw = document.getElementById('switcher');
  if(sw){
    var here = location.pathname.split('/').pop() || 'home-a.html';
    var defs = [['home-a.html','A'],['home-b.html','B'],['home-c.html','C']];
    sw.innerHTML = '<div class="sw-label">é¦–é??‡ã‚¶?¤ãƒ³æ¡?/div>' + defs.map(function(d){
      var on = here===d[0] ? ' on':'';
      return '<a class="sw-btn'+on+'" href="'+d[0]+'">'+d[1]+'</a>';
    }).join('');
    var st = document.createElement('style');
    st.textContent = '#switcher{position:fixed;right:18px;bottom:18px;z-index:200;background:rgba(26,24,21,.92);backdrop-filter:blur(8px);border-radius:40px;padding:8px 10px;display:flex;align-items:center;gap:7px;box-shadow:0 8px 30px rgba(0,0,0,.18)}'+
      '#switcher .sw-label{font-family:var(--mincho);font-size:11px;color:rgba(245,243,239,.6);padding:0 8px 0 6px;letter-spacing:.08em}'+
      '#switcher .sw-btn{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-family:var(--latin);font-size:12px;font-weight:600;color:rgba(245,243,239,.7);transition:.25s}'+
      '#switcher .sw-btn:hover{background:rgba(255,255,255,.14);color:#fff}'+
      '#switcher .sw-btn.on{background:#f5f3ef;color:#1a1815}'+
      '@media(max-width:560px){#switcher .sw-label{display:none}}';
    document.head.appendChild(st);
  }
})();

;/* KAU WordPress visual editor bridge */
if (!window.KAU_VISUAL_EDITOR_BUNDLE_LOADED) {
  window.KAU_VISUAL_EDITOR_BUNDLE_LOADED = true;
  (function () {
    var configElement = document.getElementById('kau-ve-config');
    if (configElement && !window.KAU_VE_CONFIG) {
      try { window.KAU_VE_CONFIG = JSON.parse(configElement.textContent || '{}'); } catch (error) { window.KAU_VE_CONFIG = {}; }
    }
  }());
(function () {
  "use strict";

  var script = document.currentScript;
  var embeddedConfig = {};
  try {
    embeddedConfig = script && script.dataset && script.dataset.kauConfig ? JSON.parse(script.dataset.kauConfig) : {};
  } catch (error) {
    embeddedConfig = {};
  }
  var cfg = window.KAU_VE_CONFIG || embeddedConfig || {};
  window.KAU_VE_CONFIG = cfg;
  var editIndex = null;
  var staticBase = (cfg.staticBase || "https://kau-jp.github.io/").replace(/\/?$/, "/");

  function unique(list) {
    var seen = new Set();
    return list.filter(function (item) {
      if (!item || seen.has(item)) return false;
      seen.add(item);
      return true;
    });
  }

  function isInsideEditor(element) {
    return !!(element && element.closest && element.closest("#kau-ve-toolbar, #kau-ve-enter"));
  }

  function normalizeStaticUrl(value) {
    if (!value || typeof value !== "string") return value;
    var origin = window.location.origin;
    if (value.indexOf("assets/") === 0 || value.indexOf("media/") === 0) {
      return staticBase + value;
    }
    if (value.indexOf("/assets/") === 0 || value.indexOf("/media/") === 0) {
      return staticBase + value.replace(/^\//, "");
    }
    if (value.indexOf(origin + "/assets/") === 0 || value.indexOf(origin + "/media/") === 0) {
      return staticBase + value.slice(origin.length + 1);
    }
    return value;
  }

  function normalizeStaticPaths(root) {
    Array.prototype.slice.call((root || document).querySelectorAll("[src], [href]")).forEach(function (element) {
      ["src", "href"].forEach(function (attr) {
        if (!element.hasAttribute(attr)) return;
        var next = normalizeStaticUrl(element.getAttribute(attr));
        if (next && next !== element.getAttribute(attr)) {
          element.setAttribute(attr, next);
        }
      });
    });
  }

  function hasUsefulText(element) {
    if (!element || isInsideEditor(element)) return false;
    var text = (element.textContent || "").replace(/\s+/g, " ").trim();
    if (!text) return false;
    if (element.matches("script, style, noscript, svg, path")) return false;
    return true;
  }

  function buildTextElements() {
    var selectors = [
      "h1", "h2", "h3", "h4", "h5",
      "p", "a", "button", "dt", "dd", "li",
      ".eyebrow", ".sub", ".lead", ".big", ".title",
      ".nm", ".pr", ".gd", ".gtag", ".cat", ".pname", ".pdesc", ".pprice",
      ".tag-pill", ".d", ".h", ".k", ".v",
      ".footer-addr", ".footer-bottom span"
    ].join(",");
    var candidates = unique(Array.prototype.slice.call(document.querySelectorAll(selectors)));
    return candidates.filter(function (element) {
      if (!hasUsefulText(element)) return false;
      var parent = element.parentElement ? element.parentElement.closest(selectors) : null;
      if (parent && parent !== element && !parent.matches(".footer-addr")) return false;
      return true;
    });
  }

  function buildIndex() {
    var text = {};
    buildTextElements().forEach(function (element, index) {
      var id = "text-" + index;
      element.dataset.kauVeTextId = id;
      text[id] = element;
    });

    var links = {};
    Array.prototype.slice.call(document.querySelectorAll("a[href]")).forEach(function (element, index) {
      if (isInsideEditor(element)) return;
      var id = "link-" + index;
      element.dataset.kauVeLinkId = id;
      links[id] = element;
    });

    var media = {};
    Array.prototype.slice.call(document.querySelectorAll("img, image-slot")).forEach(function (element, index) {
      if (isInsideEditor(element)) return;
      var id = "media-" + index;
      element.dataset.kauVeMediaId = id;
      media[id] = element;
    });

    editIndex = { text: text, link: links, media: media };
    return editIndex;
  }

  function setMediaSource(element, src) {
    if (!element || !src) return;
    element.setAttribute("src", normalizeStaticUrl(src));
    if ((element.tagName || "").toLowerCase() === "image-slot") {
      var parent = element.parentNode;
      var next = element.nextSibling;
      if (parent) {
        parent.removeChild(element);
        parent.insertBefore(element, next);
      }
    }
  }

  function applyEdits() {
    var edits = cfg.edits || {};
    normalizeStaticPaths(document);
    var index = buildIndex();

    Object.keys(edits.text || {}).forEach(function (id) {
      if (index.text[id] && typeof edits.text[id].html === "string") {
        index.text[id].innerHTML = edits.text[id].html;
      }
    });

    Object.keys(edits.link || {}).forEach(function (id) {
      if (index.link[id] && edits.link[id].href) {
        index.link[id].setAttribute("href", edits.link[id].href);
      }
    });

    Object.keys(edits.media || {}).forEach(function (id) {
      if (index.media[id] && edits.media[id].src) {
        setMediaSource(index.media[id], edits.media[id].src);
      }
    });
  }

  window.KAUVE = {
    buildIndex: buildIndex,
    applyEdits: applyEdits,
    setMediaSource: setMediaSource,
    normalizeStaticPaths: normalizeStaticPaths,
    getIndex: function () { return editIndex || buildIndex(); },
    buildTextElements: buildTextElements
  };

  if (window.MutationObserver) {
    var pending = false;
    new MutationObserver(function () {
      if (pending) return;
      pending = true;
      window.setTimeout(function () {
        pending = false;
        normalizeStaticPaths(document);
      }, 0);
    }).observe(document.documentElement, {
      attributes: true,
      attributeFilter: ["src", "href"],
      childList: true,
      subtree: true
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", applyEdits);
  } else {
    applyEdits();
  }
}());

  (function () {
    var cfg = window.KAU_VE_CONFIG || {};
    if (!cfg.editMode) return;
    var style = document.createElement('style');
    style.setAttribute('data-kau-ve-style', '1');
    style.textContent = {"value":"#kau-ve-toolbar {\n  position: fixed;\n  left: 50%;\n  bottom: 18px;\n  z-index: 2147483000;\n  display: flex;\n  align-items: center;\n  gap: 8px;\n  max-width: calc(100vw - 28px);\n  padding: 10px;\n  color: #fff;\n  background: rgba(0, 27, 61, .94);\n  border: 1px solid rgba(255, 255, 255, .18);\n  border-radius: 999px;\n  box-shadow: 0 18px 48px rgba(0, 0, 0, .32);\n  font: 600 13px/1 system-ui, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif;\n  transform: translateX(-50%);\n  backdrop-filter: blur(14px);\n}\n\n#kau-ve-toolbar button,\n#kau-ve-toolbar a {\n  display: inline-flex;\n  align-items: center;\n  justify-content: center;\n  min-height: 34px;\n  padding: 0 14px;\n  color: inherit;\n  background: rgba(255, 255, 255, .1);\n  border: 1px solid rgba(255, 255, 255, .12);\n  border-radius: 999px;\n  font: inherit;\n  text-decoration: none;\n  cursor: pointer;\n  white-space: nowrap;\n}\n\n#kau-ve-toolbar button:hover,\n#kau-ve-toolbar a:hover {\n  background: rgba(255, 255, 255, .18);\n}\n\n#kau-ve-toolbar .kau-ve-save {\n  color: #001b3d;\n  background: #d7ab1a;\n  border-color: #d7ab1a;\n}\n\n#kau-ve-toolbar .kau-ve-status {\n  max-width: 240px;\n  overflow: hidden;\n  color: rgba(255, 255, 255, .72);\n  text-overflow: ellipsis;\n  white-space: nowrap;\n}\n\n.kau-ve-editable {\n  outline: 1px dashed rgba(215, 171, 26, .7);\n  outline-offset: 4px;\n  cursor: text;\n}\n\n.kau-ve-editable:hover,\n.kau-ve-selected {\n  outline: 2px solid #d7ab1a !important;\n  outline-offset: 5px;\n}\n\n.kau-ve-media-editable {\n  cursor: pointer;\n  box-shadow: 0 0 0 2px rgba(215, 171, 26, .8);\n}\n\n.kau-ve-link-panel {\n  position: fixed;\n  right: 18px;\n  bottom: 78px;\n  z-index: 2147483000;\n  display: none;\n  width: min(420px, calc(100vw - 28px));\n  padding: 12px;\n  background: #fff;\n  border: 1px solid rgba(0, 0, 0, .12);\n  border-radius: 12px;\n  box-shadow: 0 18px 48px rgba(0, 0, 0, .24);\n  font: 13px/1.4 system-ui, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif;\n}\n\n.kau-ve-link-panel.open {\n  display: block;\n}\n\n.kau-ve-link-panel label {\n  display: block;\n  margin-bottom: 6px;\n  color: #334155;\n  font-weight: 700;\n}\n\n.kau-ve-link-panel input {\n  width: 100%;\n  height: 36px;\n  padding: 0 10px;\n  border: 1px solid #cbd5e1;\n  border-radius: 8px;\n  font: inherit;\n}\n\n.kau-ve-link-panel .kau-ve-row {\n  display: flex;\n  gap: 8px;\n  justify-content: flex-end;\n  margin-top: 10px;\n}\n\n.kau-ve-link-panel button {\n  min-height: 32px;\n  padding: 0 12px;\n  border: 1px solid #cbd5e1;\n  border-radius: 999px;\n  background: #fff;\n  cursor: pointer;\n}\n\n.kau-ve-link-panel .primary {\n  color: #fff;\n  background: #001b3d;\n  border-color: #001b3d;\n}\n\n@media (max-width: 720px) {\n  #kau-ve-toolbar {\n    left: 12px;\n    right: 12px;\n    flex-wrap: wrap;\n    justify-content: center;\n    border-radius: 18px;\n    transform: none;\n  }\n}\n\n","PSPath":"D:\\Akiu\\KAU\\KAU-offline\\assets\\site-tools.css","PSParentPath":"D:\\Akiu\\KAU\\KAU-offline\\assets","PSChildName":"site-tools.css","PSDrive":{"CurrentLocation":"Akiu\\KAU\\KAU-offline","Name":"D","Provider":{"ImplementingType":"Microsoft.PowerShell.Commands.FileSystemProvider","HelpFile":"System.Management.Automation.dll-Help.xml","Name":"FileSystem","PSSnapIn":"Microsoft.PowerShell.Core","ModuleName":"Microsoft.PowerShell.Core","Module":null,"Description":"","Capabilities":52,"Home":"C:\\Users\\user","Drives":"C D G E F P W"},"Root":"D:\\","Description":"DATA","MaximumSize":null,"Credential":{"UserName":null,"Password":null},"DisplayRoot":null},"PSProvider":{"ImplementingType":{"Module":"System.Management.Automation.dll","Assembly":"System.Management.Automation, Version=3.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35","TypeHandle":"System.RuntimeTypeHandle","DeclaringMethod":null,"BaseType":"System.Management.Automation.Provider.NavigationCmdletProvider","UnderlyingSystemType":"Microsoft.PowerShell.Commands.FileSystemProvider","FullName":"Microsoft.PowerShell.Commands.FileSystemProvider","AssemblyQualifiedName":"Microsoft.PowerShell.Commands.FileSystemProvider, System.Management.Automation, Version=3.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35","Namespace":"Microsoft.PowerShell.Commands","GUID":"b4755d19-b6a7-38dc-ae06-4167f801062f","IsEnum":false,"GenericParameterAttributes":null,"IsSecurityCritical":true,"IsSecuritySafeCritical":false,"IsSecurityTransparent":false,"IsGenericTypeDefinition":false,"IsGenericParameter":false,"GenericParameterPosition":null,"IsGenericType":false,"IsConstructedGenericType":false,"ContainsGenericParameters":false,"StructLayoutAttribute":"System.Runtime.InteropServices.StructLayoutAttribute","Name":"FileSystemProvider","MemberType":32,"DeclaringType":null,"ReflectedType":null,"MetadataToken":33556363,"GenericTypeParameters":"","DeclaredConstructors":"Void .ctor() Void .cctor()","DeclaredEvents":"","DeclaredFields":"System.Collections.ObjectModel.Collection`1[System.Management.Automation.WildcardPattern] excludeMatcher System.Management.Automation.PSTraceSource tracer Int32 FILETRANSFERSIZE System.String ProviderName","DeclaredMembers":"System.String NormalizePath(System.String) System.IO.FileSystemInfo GetFileSystemInfo(System.String, Boolean ByRef) Boolean IsFilterSet() System.Object GetChildNamesDynamicParameters(System.String) System.Object GetChildItemsDynamicParameters(System.String, Boolean) System.Object CopyItemDynamicParameters(System.String, System.String, Boolean) Boolean IsNetworkMappedDrive(System.Management.Automation.PSDriveInfo) Boolean IsSupportedDriveForPersistence(System.Management.Automation.PSDriveInfo) System.String GetRootPathForNetworkDriveOrDosDevice(System.IO.DriveInfo) System.Collections.ObjectModel.Collection`1[System.Management.Automation.PSDriveInfo] InitializeDefaultDrives() System.Object GetItemDynamicParameters(System.String) Void InvokeDefaultAction(System.String) Void GetChildItems(System.String, Boolean, UInt32) Void GetChildNames(System.String, System.Management.Automation.ReturnContainers) Boolean CheckItemExists(System.String, Boolean ByRef) System.Object RemoveItemDynamicParameters(System.String, Boolean) Void RemoveFileInfoItem(System.IO.FileInfo, Boolean) Boolean ItemExists(System.String) System.Object ItemExistsDynamicParameters(System.String) Boolean HasChildItems(System.String) Void CopyItemLocalOrToSession(System.String, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) Void InitilizeFunctionPSCopyFileFromRemoteSession(System.Management.Automation.PowerShell) Boolean ValidRemoteSessionForScripting(System.Management.Automation.Runspaces.Runspace) Void InitilizeFunctionsPSCopyFileToRemoteSession(System.Management.Automation.PowerShell) Boolean PathIsReservedDeviceName(System.String, System.String) Boolean IsAbsolutePath(System.String) System.String GetCommonBase(System.String, System.String) System.String CreateNormalizedRelativePathFromStack(System.Collections.Generic.Stack`1[System.String]) Boolean IsItemContainer(System.String) Boolean IsSameVolume(System.String, System.String) System.Object GetPropertyDynamicParameters(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Object SetPropertyDynamicParameters(System.String, System.Management.Automation.PSObject) System.Object ClearPropertyDynamicParameters(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Object GetContentWriterDynamicParameters(System.String) System.Object ClearContentDynamicParameters(System.String) Int32 SafeGetFileAttributes(System.String) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptorFromPath(System.String, System.Security.AccessControl.AccessControlSections) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptorOfType(System.String, System.Security.AccessControl.AccessControlSections) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptor(ItemType) System.Management.Automation.ErrorRecord CreateErrorRecord(System.String, System.String) System.String GetHelpMaml(System.String, System.String) System.Management.Automation.ProviderInfo Start(System.Management.Automation.ProviderInfo) System.Management.Automation.PSDriveInfo NewDrive(System.Management.Automation.PSDriveInfo) Void MapNetworkDrive(System.Management.Automation.PSDriveInfo) System.Management.Automation.PSDriveInfo RemoveDrive(System.Management.Automation.PSDriveInfo) System.String GetUNCForNetworkDrive(System.String) System.String GetSubstitutedPathForNetworkDosDevice(System.String) Boolean IsValidPath(System.String) Void GetItem(System.String) System.IO.FileSystemInfo GetFileSystemItem(System.String, Boolean ByRef, Boolean) Boolean ConvertPath(System.String, System.String, System.String ByRef, System.String ByRef) Void GetPathItems(System.String, Boolean, UInt32, Boolean, System.Management.Automation.ReturnContainers) Void Dir(System.IO.DirectoryInfo, Boolean, UInt32, Boolean, System.Management.Automation.ReturnContainers, InodeTracker) System.Management.Automation.FlagsExpression`1[System.IO.FileAttributes] FormatAttributeSwitchParamters() System.String Mode(System.Management.Automation.PSObject) Void RenameItem(System.String, System.String) Void NewItem(System.String, System.String, System.Object) ItemType GetItemType(System.String) Void CreateDirectory(System.String, Boolean) Boolean CreateIntermediateDirectories(System.String) Void RemoveItem(System.String, Boolean) Void RemoveDirectoryInfoItem(System.IO.DirectoryInfo, Boolean, Boolean, Boolean) Void RemoveFileSystemItem(System.IO.FileSystemInfo, Boolean) Boolean ItemExists(System.String, System.Management.Automation.ErrorRecord ByRef) Boolean DirectoryInfoHasChildItems(System.IO.DirectoryInfo) Void CopyItem(System.String, System.String, Boolean) Void CopyItemFromRemoteSession(System.String, System.String, Boolean, Boolean, System.Management.Automation.Runspaces.PSSession) Void CopyDirectoryInfoItem(System.IO.DirectoryInfo, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) Void CopyFileInfoItem(System.IO.FileInfo, System.String, Boolean, System.Management.Automation.PowerShell) Void CopyDirectoryFromRemoteSession(System.String, System.String, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) System.Collections.ArrayList GetRemoteSourceAlternateStreams(System.Management.Automation.PowerShell, System.String) Void RemoveFunctionsPSCopyFileFromRemoteSession(System.Management.Automation.PowerShell) System.Collections.Hashtable GetRemoteFileMetadata(System.String, System.Management.Automation.PowerShell) Void SetFileMetadata(System.String, System.IO.FileInfo, System.Management.Automation.PowerShell) Void CopyFileFromRemoteSession(System.String, System.String, System.String, Boolean, System.Management.Automation.PowerShell, Int64) Boolean PerformCopyFileFromRemoteSession(System.String, System.IO.FileInfo, System.String, Boolean, System.Management.Automation.PowerShell, Int64, Boolean, System.String) Void RemoveFunctionPSCopyFileToRemoteSession(System.Management.Automation.PowerShell) Boolean RemoteTargetSupportsAlternateStreams(System.Management.Automation.PowerShell, System.String) System.String MakeRemotePath(System.Management.Automation.PowerShell, System.String, System.String) Boolean RemoteDirectoryExist(System.Management.Automation.PowerShell, System.String) Boolean CopyFileStreamToRemoteSession(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell, Boolean, System.String) System.Collections.Hashtable GetFileMetadata(System.IO.FileInfo) Void SetRemoteFileMetadata(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell) Boolean PerformCopyFileToRemoteSession(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell) Boolean RemoteDestinationPathIsFile(System.String, System.Management.Automation.PowerShell) System.String CreateDirectoryOnRemoteSession(System.String, Boolean, System.Management.Automation.PowerShell) System.String GetParentPath(System.String, System.String) Boolean IsUNCPath(System.String) Boolean IsUNCRoot(System.String) Boolean IsPathRoot(System.String) System.String NormalizeRelativePath(System.String, System.String) System.String NormalizeRelativePathHelper(System.String, System.String) System.String RemoveRelativeTokens(System.String) System.Collections.Generic.Stack`1[System.String] TokenizePathToStack(System.String, System.String) System.Collections.Generic.Stack`1[System.String] NormalizeThePath(System.String, System.Collections.Generic.Stack`1[System.String]) System.String GetChildName(System.String) System.String EnsureDriveIsRooted(System.String) Void MoveItem(System.String, System.String) Void MoveFileInfoItem(System.IO.FileInfo, System.String, Boolean, Boolean) Void MoveDirectoryInfoItem(System.IO.DirectoryInfo, System.String, Boolean) Void CopyAndDelete(System.IO.DirectoryInfo, System.String, Boolean) Void GetProperty(System.String, System.Collections.ObjectModel.Collection`1[System.String]) Void SetProperty(System.String, System.Management.Automation.PSObject) Void ClearProperty(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Management.Automation.Provider.IContentReader GetContentReader(System.String) System.Object GetContentReaderDynamicParameters(System.String) System.Management.Automation.Provider.IContentWriter GetContentWriter(System.String) Void ClearContent(System.String) Void ValidateParameters(Boolean) Void GetSecurityDescriptor(System.String, System.Security.AccessControl.AccessControlSections) Void SetSecurityDescriptor(System.String, System.Security.AccessControl.ObjectSecurity) Void SetSecurityDescriptor(System.String, System.Security.AccessControl.ObjectSecurity, System.Security.AccessControl.AccessControlSections) Void \u003cRemoveDirectoryInfoItem\u003eg__WriteErrorHelper|43_0(System.Exception, \u003c\u003ec__DisplayClass43_0 ByRef) Void .ctor() Void .cctor() System.Collections.ObjectModel.Collection`1[System.Management.Automation.WildcardPattern] excludeMatcher System.Management.Automation.PSTraceSource tracer Int32 FILETRANSFERSIZE System.String ProviderName Microsoft.PowerShell.Commands.FileSystemProvider+ItemType Microsoft.PowerShell.Commands.FileSystemProvider+NativeMethods Microsoft.PowerShell.Commands.FileSystemProvider+NetResource Microsoft.PowerShell.Commands.FileSystemProvider+InodeTracker Microsoft.PowerShell.Commands.FileSystemProvider+\u003c\u003ec__DisplayClass43_0","DeclaredMethods":"System.String Mode(System.Management.Automation.PSObject) System.String NormalizePath(System.String) System.IO.FileSystemInfo GetFileSystemInfo(System.String, Boolean ByRef) Boolean IsFilterSet() System.Object GetChildNamesDynamicParameters(System.String) System.Object GetChildItemsDynamicParameters(System.String, Boolean) System.Object CopyItemDynamicParameters(System.String, System.String, Boolean) Boolean IsNetworkMappedDrive(System.Management.Automation.PSDriveInfo) Boolean IsSupportedDriveForPersistence(System.Management.Automation.PSDriveInfo) System.String GetRootPathForNetworkDriveOrDosDevice(System.IO.DriveInfo) System.Collections.ObjectModel.Collection`1[System.Management.Automation.PSDriveInfo] InitializeDefaultDrives() System.Object GetItemDynamicParameters(System.String) Void InvokeDefaultAction(System.String) Void GetChildItems(System.String, Boolean, UInt32) Void GetChildNames(System.String, System.Management.Automation.ReturnContainers) Boolean CheckItemExists(System.String, Boolean ByRef) System.Object RemoveItemDynamicParameters(System.String, Boolean) Void RemoveFileInfoItem(System.IO.FileInfo, Boolean) Boolean ItemExists(System.String) System.Object ItemExistsDynamicParameters(System.String) Boolean HasChildItems(System.String) Void CopyItemLocalOrToSession(System.String, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) Void InitilizeFunctionPSCopyFileFromRemoteSession(System.Management.Automation.PowerShell) Boolean ValidRemoteSessionForScripting(System.Management.Automation.Runspaces.Runspace) Void InitilizeFunctionsPSCopyFileToRemoteSession(System.Management.Automation.PowerShell) Boolean PathIsReservedDeviceName(System.String, System.String) Boolean IsAbsolutePath(System.String) System.String GetCommonBase(System.String, System.String) System.String CreateNormalizedRelativePathFromStack(System.Collections.Generic.Stack`1[System.String]) Boolean IsItemContainer(System.String) Boolean IsSameVolume(System.String, System.String) System.Object GetPropertyDynamicParameters(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Object SetPropertyDynamicParameters(System.String, System.Management.Automation.PSObject) System.Object ClearPropertyDynamicParameters(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Object GetContentWriterDynamicParameters(System.String) System.Object ClearContentDynamicParameters(System.String) Int32 SafeGetFileAttributes(System.String) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptorFromPath(System.String, System.Security.AccessControl.AccessControlSections) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptorOfType(System.String, System.Security.AccessControl.AccessControlSections) System.Security.AccessControl.ObjectSecurity NewSecurityDescriptor(ItemType) System.Management.Automation.ErrorRecord CreateErrorRecord(System.String, System.String) System.String GetHelpMaml(System.String, System.String) System.Management.Automation.ProviderInfo Start(System.Management.Automation.ProviderInfo) System.Management.Automation.PSDriveInfo NewDrive(System.Management.Automation.PSDriveInfo) Void MapNetworkDrive(System.Management.Automation.PSDriveInfo) System.Management.Automation.PSDriveInfo RemoveDrive(System.Management.Automation.PSDriveInfo) System.String GetUNCForNetworkDrive(System.String) System.String GetSubstitutedPathForNetworkDosDevice(System.String) Boolean IsValidPath(System.String) Void GetItem(System.String) System.IO.FileSystemInfo GetFileSystemItem(System.String, Boolean ByRef, Boolean) Boolean ConvertPath(System.String, System.String, System.String ByRef, System.String ByRef) Void GetPathItems(System.String, Boolean, UInt32, Boolean, System.Management.Automation.ReturnContainers) Void Dir(System.IO.DirectoryInfo, Boolean, UInt32, Boolean, System.Management.Automation.ReturnContainers, InodeTracker) System.Management.Automation.FlagsExpression`1[System.IO.FileAttributes] FormatAttributeSwitchParamters() Void RenameItem(System.String, System.String) Void NewItem(System.String, System.String, System.Object) ItemType GetItemType(System.String) Void CreateDirectory(System.String, Boolean) Boolean CreateIntermediateDirectories(System.String) Void RemoveItem(System.String, Boolean) Void RemoveDirectoryInfoItem(System.IO.DirectoryInfo, Boolean, Boolean, Boolean) Void RemoveFileSystemItem(System.IO.FileSystemInfo, Boolean) Boolean ItemExists(System.String, System.Management.Automation.ErrorRecord ByRef) Boolean DirectoryInfoHasChildItems(System.IO.DirectoryInfo) Void CopyItem(System.String, System.String, Boolean) Void CopyItemFromRemoteSession(System.String, System.String, Boolean, Boolean, System.Management.Automation.Runspaces.PSSession) Void CopyDirectoryInfoItem(System.IO.DirectoryInfo, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) Void CopyFileInfoItem(System.IO.FileInfo, System.String, Boolean, System.Management.Automation.PowerShell) Void CopyDirectoryFromRemoteSession(System.String, System.String, System.String, Boolean, Boolean, System.Management.Automation.PowerShell) System.Collections.ArrayList GetRemoteSourceAlternateStreams(System.Management.Automation.PowerShell, System.String) Void RemoveFunctionsPSCopyFileFromRemoteSession(System.Management.Automation.PowerShell) System.Collections.Hashtable GetRemoteFileMetadata(System.String, System.Management.Automation.PowerShell) Void SetFileMetadata(System.String, System.IO.FileInfo, System.Management.Automation.PowerShell) Void CopyFileFromRemoteSession(System.String, System.String, System.String, Boolean, System.Management.Automation.PowerShell, Int64) Boolean PerformCopyFileFromRemoteSession(System.String, System.IO.FileInfo, System.String, Boolean, System.Management.Automation.PowerShell, Int64, Boolean, System.String) Void RemoveFunctionPSCopyFileToRemoteSession(System.Management.Automation.PowerShell) Boolean RemoteTargetSupportsAlternateStreams(System.Management.Automation.PowerShell, System.String) System.String MakeRemotePath(System.Management.Automation.PowerShell, System.String, System.String) Boolean RemoteDirectoryExist(System.Management.Automation.PowerShell, System.String) Boolean CopyFileStreamToRemoteSession(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell, Boolean, System.String) System.Collections.Hashtable GetFileMetadata(System.IO.FileInfo) Void SetRemoteFileMetadata(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell) Boolean PerformCopyFileToRemoteSession(System.IO.FileInfo, System.String, System.Management.Automation.PowerShell) Boolean RemoteDestinationPathIsFile(System.String, System.Management.Automation.PowerShell) System.String CreateDirectoryOnRemoteSession(System.String, Boolean, System.Management.Automation.PowerShell) System.String GetParentPath(System.String, System.String) Boolean IsUNCPath(System.String) Boolean IsUNCRoot(System.String) Boolean IsPathRoot(System.String) System.String NormalizeRelativePath(System.String, System.String) System.String NormalizeRelativePathHelper(System.String, System.String) System.String RemoveRelativeTokens(System.String) System.Collections.Generic.Stack`1[System.String] TokenizePathToStack(System.String, System.String) System.Collections.Generic.Stack`1[System.String] NormalizeThePath(System.String, System.Collections.Generic.Stack`1[System.String]) System.String GetChildName(System.String) System.String EnsureDriveIsRooted(System.String) Void MoveItem(System.String, System.String) Void MoveFileInfoItem(System.IO.FileInfo, System.String, Boolean, Boolean) Void MoveDirectoryInfoItem(System.IO.DirectoryInfo, System.String, Boolean) Void CopyAndDelete(System.IO.DirectoryInfo, System.String, Boolean) Void GetProperty(System.String, System.Collections.ObjectModel.Collection`1[System.String]) Void SetProperty(System.String, System.Management.Automation.PSObject) Void ClearProperty(System.String, System.Collections.ObjectModel.Collection`1[System.String]) System.Management.Automation.Provider.IContentReader GetContentReader(System.String) System.Object GetContentReaderDynamicParameters(System.String) System.Management.Automation.Provider.IContentWriter GetContentWriter(System.String) Void ClearContent(System.String) Void ValidateParameters(Boolean) Void GetSecurityDescriptor(System.String, System.Security.AccessControl.AccessControlSections) Void SetSecurityDescriptor(System.String, System.Security.AccessControl.ObjectSecurity) Void SetSecurityDescriptor(System.String, System.Security.AccessControl.ObjectSecurity, System.Security.AccessControl.AccessControlSections) Void \u003cRemoveDirectoryInfoItem\u003eg__WriteErrorHelper|43_0(System.Exception, \u003c\u003ec__DisplayClass43_0 ByRef)","DeclaredNestedTypes":"Microsoft.PowerShell.Commands.FileSystemProvider+ItemType Microsoft.PowerShell.Commands.FileSystemProvider+NativeMethods Microsoft.PowerShell.Commands.FileSystemProvider+NetResource Microsoft.PowerShell.Commands.FileSystemProvider+InodeTracker Microsoft.PowerShell.Commands.FileSystemProvider+\u003c\u003ec__DisplayClass43_0","DeclaredProperties":"","ImplementedInterfaces":"System.Management.Automation.IResourceSupplier System.Management.Automation.Provider.IContentCmdletProvider System.Management.Automation.Provider.IPropertyCmdletProvider System.Management.Automation.Provider.ISecurityDescriptorCmdletProvider System.Management.Automation.Provider.ICmdletProviderSupportsHelp","TypeInitializer":"Void .cctor()","IsNested":false,"Attributes":1048833,"IsVisible":true,"IsNotPublic":false,"IsPublic":true,"IsNestedPublic":false,"IsNestedPrivate":false,"IsNestedFamily":false,"IsNestedAssembly":false,"IsNestedFamANDAssem":false,"IsNestedFamORAssem":false,"IsAutoLayout":true,"IsLayoutSequential":false,"IsExplicitLayout":false,"IsClass":true,"IsInterface":false,"IsValueType":false,"IsAbstract":false,"IsSealed":true,"IsSpecialName":false,"IsImport":false,"IsSerializable":false,"IsAnsiClass":true,"IsUnicodeClass":false,"IsAutoClass":false,"IsArray":false,"IsByRef":false,"IsPointer":false,"IsPrimitive":false,"IsCOMObject":false,"HasElementType":false,"IsContextful":false,"IsMarshalByRef":false,"GenericTypeArguments":"","CustomAttributes":"[System.Management.Automation.OutputTypeAttribute(typeof(System.Management.Automation.PathInfo), ProviderCmdlet = \"Push-Location\")] [System.Management.Automation.OutputTypeAttribute(typeof(System.Security.AccessControl.FileSecurity), ProviderCmdlet = \"Set-Acl\")] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.String), typeof(System.Management.Automation.PathInfo) }, ProviderCmdlet = \"Resolve-Path\")] [System.Management.Automation.Provider.CmdletProviderAttribute(\"FileSystem\", (System.Management.Automation.Provider.ProviderCapabilities)52)] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.Byte), typeof(System.String) }, ProviderCmdlet = \"Get-Content\")] [System.Management.Automation.OutputTypeAttribute(typeof(System.IO.FileInfo), ProviderCmdlet = \"Get-Item\")] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.IO.FileInfo), typeof(System.IO.DirectoryInfo) }, ProviderCmdlet = \"Get-ChildItem\")] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.Security.AccessControl.FileSecurity), typeof(System.Security.AccessControl.DirectorySecurity) }, ProviderCmdlet = \"Get-Acl\")] [System.Management.Automation.OutputTypeAttribute(new Type[4] { typeof(System.Boolean), typeof(System.String), typeof(System.IO.FileInfo), typeof(System.IO.DirectoryInfo) }, ProviderCmdlet = \"Get-Item\")] [System.Management.Automation.OutputTypeAttribute(new Type[5] { typeof(System.Boolean), typeof(System.String), typeof(System.DateTime), typeof(System.IO.FileInfo), typeof(System.IO.DirectoryInfo) }, ProviderCmdlet = \"Get-ItemProperty\")] [System.Management.Automation.OutputTypeAttribute(new Type[2] { typeof(System.String), typeof(System.IO.FileInfo) }, ProviderCmdlet = \"New-Item\")]"},"HelpFile":"System.Management.Automation.dll-Help.xml","Name":"FileSystem","PSSnapIn":{"Name":"Microsoft.PowerShell.Core","IsDefault":true,"ApplicationBase":"C:\\Windows\\System32\\WindowsPowerShell\\v1.0","AssemblyName":"System.Management.Automation, Version=3.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35, ProcessorArchitecture=MSIL","ModuleName":"C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\System.Management.Automation.dll","PSVersion":"5.1.19041.6456","Version":"3.0.0.0","Types":"types.ps1xml typesv3.ps1xml","Formats":"Certificate.format.ps1xml DotNetTypes.format.ps1xml FileSystem.format.ps1xml Help.format.ps1xml HelpV3.format.ps1xml PowerShellCore.format.ps1xml PowerShellTrace.format.ps1xml Registry.format.ps1xml","Description":"","Vendor":"","LogPipelineExecutionDetails":false},"ModuleName":"Microsoft.PowerShell.Core","Module":null,"Description":"","Capabilities":52,"Home":"C:\\Users\\user","Drives":["C","D","G","E","F","P","W"]},"ReadCount":1};
    document.head.appendChild(style);
  }());
(function () {
  "use strict";

  var cfg = window.KAU_VE_CONFIG || {};
  if (!cfg.editMode || !window.KAUVE) return;

  var dirty = false;
  var selectedLink = null;
  var fileInput = null;
  var linkPanel = null;
  var linkInput = null;

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function status(text) {
    var element = qs("#kau-ve-status");
    if (element) element.textContent = text;
  }

  function markDirty() {
    dirty = true;
    status("Unsaved");
  }

  function buildToolbar() {
    var toolbar = document.createElement("div");
    toolbar.id = "kau-ve-toolbar";
    toolbar.innerHTML = [
      "<span>KAU Visual Edit</span>",
      "<button type=\"button\" class=\"kau-ve-save\" id=\"kau-ve-save\">Save</button>",
      "<button type=\"button\" id=\"kau-ve-link\">Link</button>",
      "<a id=\"kau-ve-view\" href=\"" + escapeAttr(cfg.viewUrl || location.pathname) + "\">Exit</a>",
      "<span class=\"kau-ve-status\" id=\"kau-ve-status\">Click text to edit</span>"
    ].join("");
    document.body.appendChild(toolbar);

    linkPanel = document.createElement("div");
    linkPanel.className = "kau-ve-link-panel";
    linkPanel.innerHTML = [
      "<label for=\"kau-ve-link-url\">Link URL</label>",
      "<input id=\"kau-ve-link-url\" type=\"text\" autocomplete=\"off\">",
      "<div class=\"kau-ve-row\">",
      "<button type=\"button\" id=\"kau-ve-link-close\">Cancel</button>",
      "<button type=\"button\" class=\"primary\" id=\"kau-ve-link-apply\">Apply</button>",
      "</div>"
    ].join("");
    document.body.appendChild(linkPanel);
    linkInput = qs("#kau-ve-link-url");

    fileInput = document.createElement("input");
    fileInput.type = "file";
    fileInput.accept = "image/*";
    fileInput.style.display = "none";
    document.body.appendChild(fileInput);

    qs("#kau-ve-save").addEventListener("click", save);
    qs("#kau-ve-link").addEventListener("click", openLinkPanel);
    qs("#kau-ve-link-close").addEventListener("click", closeLinkPanel);
    qs("#kau-ve-link-apply").addEventListener("click", applyLink);
    installNavigationGuard();
  }

  function installNavigationGuard() {
    document.addEventListener("click", function (event) {
      var target = event.target && event.target.closest ? event.target.closest("a[href], button") : null;
      if (!target || isInsideEditor(target)) return;
      event.preventDefault();
      event.stopPropagation();
      var link = target.closest("a[href]");
      if (link) {
        selectedLink = link;
        clearSelection();
        link.classList.add("kau-ve-selected");
        status("Link selected. Use Link to edit URL.");
        return;
      }
      target.focus({ preventScroll: true });
      status("Button selected. Edit its text directly.");
    }, true);
  }

  function escapeAttr(value) {
    return String(value || "").replace(/&/g, "&amp;").replace(/"/g, "&quot;");
  }

  function prepareEditableElements() {
    var index = window.KAUVE.getIndex();

    Object.keys(index.text).forEach(function (id) {
      var element = index.text[id];
      element.classList.add("kau-ve-editable");
      element.setAttribute("contenteditable", "true");
      element.setAttribute("spellcheck", "false");
      element.addEventListener("input", markDirty);
      element.addEventListener("focus", function () {
        clearSelection();
        element.classList.add("kau-ve-selected");
        var link = element.closest("a[href]");
        selectedLink = link || null;
      });
      element.addEventListener("click", function (event) {
        if (element.closest("a[href]")) {
          event.preventDefault();
        }
      });
    });

    Object.keys(index.link).forEach(function (id) {
      var element = index.link[id];
      element.addEventListener("click", function (event) {
        event.preventDefault();
        selectedLink = element;
        clearSelection();
        element.classList.add("kau-ve-selected");
        status("Link selected");
      });
    });

    Object.keys(index.media).forEach(function (id) {
      var element = index.media[id];
      element.classList.add("kau-ve-media-editable");
      element.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        chooseImage(element);
      });
    });
  }

  function clearSelection() {
    qsa(".kau-ve-selected").forEach(function (element) {
      element.classList.remove("kau-ve-selected");
    });
  }

  function openLinkPanel() {
    if (!selectedLink) {
      status("Select a link first");
      return;
    }
    linkInput.value = selectedLink.getAttribute("href") || "";
    linkPanel.classList.add("open");
    linkInput.focus();
  }

  function closeLinkPanel() {
    linkPanel.classList.remove("open");
  }

  function applyLink() {
    if (!selectedLink) return;
    selectedLink.setAttribute("href", linkInput.value || "#");
    closeLinkPanel();
    markDirty();
  }

  function chooseImage(target) {
    fileInput.value = "";
    fileInput.onchange = function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      uploadImage(file).then(function (url) {
        window.KAUVE.setMediaSource(target, url);
        markDirty();
        status("Image updated. Save when ready.");
      }).catch(function (error) {
        status(error.message || "Image upload failed");
      });
    };
    fileInput.click();
  }

  function uploadImage(file) {
    var form = new FormData();
    form.append("action", "kau_ve_upload_image");
    form.append("nonce", cfg.nonce);
    form.append("file", file);

    status("Uploading image...");
    return fetch(cfg.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form
    }).then(function (response) {
      return response.json();
    }).then(function (payload) {
      if (!payload || !payload.success) {
        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : "Image upload failed");
      }
      return payload.data.url;
    });
  }

  function collectEdits() {
    var index = window.KAUVE.getIndex();
    var edits = { text: {}, link: {}, media: {} };

    Object.keys(index.text).forEach(function (id) {
      edits.text[id] = { html: index.text[id].innerHTML };
    });

    Object.keys(index.link).forEach(function (id) {
      edits.link[id] = { href: index.link[id].getAttribute("href") || "" };
    });

    Object.keys(index.media).forEach(function (id) {
      edits.media[id] = { src: index.media[id].getAttribute("src") || "" };
    });

    return edits;
  }

  function save() {
    var form = new FormData();
    form.append("action", "kau_ve_save_page");
    form.append("nonce", cfg.nonce);
    form.append("pageKey", cfg.pageKey);
    form.append("edits", JSON.stringify(collectEdits()));

    status("Saving...");
    fetch(cfg.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form
    }).then(function (response) {
      return response.json();
    }).then(function (payload) {
      if (!payload || !payload.success) {
        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : "Save failed");
      }
      dirty = false;
      status("Saved");
    }).catch(function (error) {
      status(error.message || "Save failed");
    });
  }

  window.addEventListener("beforeunload", function (event) {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  function boot() {
    buildToolbar();
    prepareEditableElements();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
}());

}
