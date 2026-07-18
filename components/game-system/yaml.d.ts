// The build (esbuild --loader:.yaml=text) and no one else imports YAML files;
// they arrive as plain strings.
declare module "*.yaml" {
  const text: string;
  export default text;
}
