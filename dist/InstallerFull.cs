using System;
using System.IO;
using System.IO.Compression;
using System.Diagnostics;
using System.Reflection;
using System.Windows.Forms;
using Microsoft.Win32;

public class Installer
{
    [STAThread]
    public static void Main()
    {
        string target = @"C:\LoaderSAE";

        if (Directory.Exists(target) && File.Exists(Path.Combine(target, "LoaderSAE.exe")))
        {
            var choice = MessageBox.Show(
              "Loader SAE sudah terinstal di:\n" + target +
              "\n\nYes = Uninstall\nNo = Install Ulang (Timpa)\nCancel = Batal",
              "Loader SAE Installer", MessageBoxButtons.YesNoCancel, MessageBoxIcon.Question);
            if (choice == DialogResult.Cancel) return;
            if (choice == DialogResult.Yes)
            {
                RunUninstall(target);
                return;
            }
        }

        Directory.CreateDirectory(target);
        string tmp = Path.Combine(Path.GetTempPath(), "loader-sae-payload.zip");
        using (Stream s = Assembly.GetExecutingAssembly().GetManifestResourceStream("payload.zip"))
        {
            if (s == null)
            {
                MessageBox.Show("Resource payload.zip tidak ditemukan.", "Loader SAE Installer", MessageBoxButtons.OK, MessageBoxIcon.Error);
                return;
            }
            using (FileStream f = File.Create(tmp)) s.CopyTo(f);
        }
        using (var archive = ZipFile.OpenRead(tmp))
        {
            foreach (var entry in archive.Entries)
            {
                string path = Path.Combine(target, entry.FullName);
                if (String.IsNullOrEmpty(entry.Name)) { Directory.CreateDirectory(path); continue; }
                Directory.CreateDirectory(Path.GetDirectoryName(path));
                entry.ExtractToFile(path, true);
            }
        }
        File.Delete(tmp);

        string php = Path.Combine(target, "php");
        AddPath(php);
        string app = Path.Combine(target, "LoaderSAE.exe");
        string icon = Path.Combine(target, "sae.ico");
        CreateShortcut(app, icon);
        RegisterUninstall(target, icon);
        Process.Start(new ProcessStartInfo(app) { UseShellExecute = true });
        MessageBox.Show("Loader SAE terinstall di C:\\LoaderSAE\nPHP portable otomatis tersedia.\nShortcut dibuat di Desktop.", "Loader SAE Installer", MessageBoxButtons.OK, MessageBoxIcon.Information);
    }

    static void RunUninstall(string target)
    {
        string uninstaller = Path.Combine(target, "Uninstall.exe");
        if (File.Exists(uninstaller))
        {
            Process.Start(new ProcessStartInfo(uninstaller) { UseShellExecute = true });
        }
        else
        {
            try { Directory.Delete(target, true); } catch { }
            MessageBox.Show("Loader SAE telah dihapus.", "Loader SAE Installer", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
    }

    static void AddPath(string dir)
    {
        try
        {
            string path = Environment.GetEnvironmentVariable("Path", EnvironmentVariableTarget.Machine) ?? "";
            if (path.IndexOf(dir, StringComparison.OrdinalIgnoreCase) < 0)
                Environment.SetEnvironmentVariable("Path", path.TrimEnd(';') + ";" + dir, EnvironmentVariableTarget.Machine);
        }
        catch
        {
            string path = Environment.GetEnvironmentVariable("Path", EnvironmentVariableTarget.User) ?? "";
            if (path.IndexOf(dir, StringComparison.OrdinalIgnoreCase) < 0)
                Environment.SetEnvironmentVariable("Path", String.IsNullOrWhiteSpace(path) ? dir : path.TrimEnd(';') + ";" + dir, EnvironmentVariableTarget.User);
        }
    }

    static void CreateShortcut(string target, string icon)
    {
        string desktop = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
        string shortcut = Path.Combine(desktop, "Loader SAE.url");
        File.WriteAllText(shortcut, "[InternetShortcut]\r\nURL=file:///" + target.Replace("\\", "/") + "\r\nIconFile=" + icon + "\r\nIconIndex=0\r\n");
    }

    static void RegisterUninstall(string target, string icon)
    {
        try
        {
            RegistryKey root;
            try { root = Registry.LocalMachine.CreateSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\LoaderSAE"); }
            catch { root = Registry.CurrentUser.CreateSubKey(@"Software\Microsoft\Windows\CurrentVersion\Uninstall\LoaderSAE"); }
            using (root)
            {
                root.SetValue("DisplayName", "Loader SAE");
                root.SetValue("DisplayIcon", icon);
                root.SetValue("Publisher", "Smart Apps Education");
                root.SetValue("InstallLocation", target);
                root.SetValue("UninstallString", "\"" + Path.Combine(target, "Uninstall.exe") + "\"");
                root.SetValue("NoModify", 1, RegistryValueKind.DWord);
                root.SetValue("NoRepair", 1, RegistryValueKind.DWord);
            }
        }
        catch { }
    }
}
