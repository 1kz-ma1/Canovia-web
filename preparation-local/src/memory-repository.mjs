import {emptyState,validate,requireValue} from './model.mjs';
// Explicit C candidate: no IndexedDB, no silent fallback from failed B storage.
export class Repository {
  static async open(){return new Repository();}
  constructor(){this.state=emptyState();this.closed=false;}
  async read(){requireValue(!this.closed);return structuredClone(this.state);}
  async change(revision,mutate){
    requireValue(!this.closed&&revision===this.state.revision,'CONFLICT');
    const next=structuredClone(this.state),result=mutate(next);requireValue(!result?.then,'ASYNC_MUTATION_REFUSED');
    next.revision++;validate(next);this.state=next;return this.read();
  }
  close(){this.closed=true;}
}
