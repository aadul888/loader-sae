using System;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Windows.Forms;
using Microsoft.Win32;

// Self-contained uninstaller: always operates on its own install folder.
public class Uninstall
{
    [STAThread]
    static void Main()
    {
        string target = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\');

        var confirm = MessageBox.Show(
          "Hapus Loader SAE dari:\n" + target + " ?",
          "Uninstall Loader SAE", MessageBoxButtons.YesNo, MessageBoxIcon.Warning);
        if (confirm != DialogResult.Yes) return;

        KillRunning(target);
        RemoveFromPath(Path.Combine(target, "php"), EnvironmentVariableTarget.User);
        RemoveFromPath(Path.Combine(target, "php"), EnvironmentVariableTarget.Machine);
        RemoveShortcut();
        RemoveRegistryEntry();
        ScheduleSelfDelete(target);

        MessageBox.Show("Loader SAE sedang dihapus...", "Uninstall Loader SAE", MessageBoxButtons.OK, MessageBoxIcon.Information);
        Environment.Exit(0);
    }

    static void KillRunning(string target)
    {
        foreach (var name in new[] { "php", "LoaderSAE" })
        {
            foreach (var p in Process.GetProcessesByName(name))
            {
                try
                {
                    string path = p.MainModule.FileName;
                    if (path.StartsWith(target, StringComparison.OrdinalIgnoreCase)) p.Kill();
                }
                catch { }
            }
        }
    }

    static void RemoveFromPath(string dir, EnvironmentVariableTarget scope)
    {
        try
        {
            string path = Environment.GetEnvironmentVariable("Path", scope) ?? "";
            var parts = path.Split(';').Where(p => p.Length > 0 && !p.Equals(dir, StringComparison.OrdinalIgnoreCase));
            Environment.SetEnvironmentVariable("Path", string.Join(";", parts), scope);
        }
        catch { }
    }

    static void RemoveShortcut()
    {
        try
        {
            string desktop = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
            string shortcut = Path.Combine(desktop, "Loader SAE.url");
            if (File.Exists(shortcut)) File.Delete(shortcut);
        }
        catch { }
    }

    static void RemoveRegistryEntry()
    {
        try { Registry.CurrentUser.DeleteSubKeyTree(@"Software\Microsoft\Windows\CurrentVersion\Uninstall\LoaderSAE", false); } catch { }
        try { Registry.LocalMachine.DeleteSubKeyTree(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\LoaderSAE", false); } catch { }
    }

    // Deletes the install folder a moment after this process exits (so its own exe is unlocked).
    static void ScheduleSelfDelete(string target)
    {
        string batch = Path.Combine(Path.GetTempPath(), "uninstall_loadersae_" + Guid.NewGuid().ToString("N") + ".bat");
        File.WriteAllText(batch,
          "@echo off\r\n" +
          "timeout /t 2 /nobreak >nul\r\n" +
          "rd /s /q \"" + target + "\"\r\n" +
          "del \"%~f0\"\r\n");
        Process.Start(new ProcessStartInfo
        {
            FileName = batch,
            WindowStyle = ProcessWindowStyle.Hidden,
            CreateNoWindow = true,
            UseShellExecute = true
        });
    }
}
