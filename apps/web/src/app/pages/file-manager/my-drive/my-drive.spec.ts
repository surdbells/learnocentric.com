import { ComponentFixture, TestBed } from '@angular/core/testing';

import { MyDrive } from './my-drive';

describe('MyDrive', () => {
  let component: MyDrive;
  let fixture: ComponentFixture<MyDrive>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [MyDrive]
    })
    .compileComponents();

    fixture = TestBed.createComponent(MyDrive);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
