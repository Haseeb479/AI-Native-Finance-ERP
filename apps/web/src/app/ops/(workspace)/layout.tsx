import { OpsShell } from "@/components/ops/OpsShell";

export default function OperationsLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <OpsShell>{children}</OpsShell>;
}
