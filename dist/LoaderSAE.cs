using System;
using System.Diagnostics;
using System.IO;
using System.Windows.Forms;

class LoaderSAE {
    [STAThread]
    static void Main() {
        string baseDir = AppDomain.CurrentDomain.BaseDirectory;
        string index = Path.Combine(baseDir, "index.php");
        if (!File.Exists(index)) {
            MessageBox.Show("index.php tidak ditemukan.", "Loader SAE", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return;
        }
        string php = FindPhp();
        if (php == null) {
            MessageBox.Show("PHP tidak ditemukan.", "Loader SAE", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return;
        }
        try {
            ProcessStartInfo psi = new ProcessStartInfo();
            psi.FileName = php;
            psi.Arguments = "-S localhost:4215 index.php";
            psi.WorkingDirectory = baseDir;
            psi.CreateNoWindow = true;
            psi.UseShellExecute = false;
            psi.WindowStyle = ProcessWindowStyle.Hidden;
            Process p = Process.Start(psi);
            Process.Start(new ProcessStartInfo("http://localhost:4215/") { UseShellExecute = true });
            p.WaitForExit();
        } catch (Exception ex) {
            MessageBox.Show("Gagal menjalankan PHP: " + ex.Message, "Loader SAE", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    static string FindPhp() {
        string local = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "php\\php.exe");
        if (File.Exists(local)) return local;
        foreach (string name in new string[] { "php.exe" }) {
            string result = RunWhere(name);
            if (result != null) return result.Split('\n')[0].Trim();
        }
        return null;
    }

    static string RunWhere(string fileName) {
        try {
            ProcessStartInfo psi = new ProcessStartInfo();
            psi.FileName = "cmd";
            psi.Arguments = "/c where " + fileName;
            psi.CreateNoWindow = true;
            psi.UseShellExecute = false;
            psi.RedirectStandardOutput = true;
            Process p = Process.Start(psi);
            p.WaitForExit();
            if (p.ExitCode == 0) return p.StandardOutput.ReadToEnd();
        } catch {}
        return null;
    }
}
